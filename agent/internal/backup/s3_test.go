package backup

import (
	"bytes"
	"context"
	"encoding/xml"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"testing"
)

// fakeS3 is enough of S3 for the client: objects, listing and multipart
// uploads, path-style, and every request must be signed.
type fakeS3 struct {
	mu      sync.Mutex
	objects map[string][]byte
	parts   map[string]map[string][]byte
	puts    int
}

func (f *fakeS3) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if !strings.HasPrefix(r.Header.Get("Authorization"), "AWS4-HMAC-SHA256 Credential=AK/") || r.Header.Get("x-amz-content-sha256") == "" {
		w.WriteHeader(http.StatusForbidden)
		fmt.Fprint(w, "<Error><Code>AccessDenied</Code><Message>unsigned</Message></Error>")
		return
	}
	bucket, key, _ := strings.Cut(strings.TrimPrefix(r.URL.Path, "/"), "/")
	if bucket != "yedek" {
		w.WriteHeader(http.StatusNotFound)
		fmt.Fprint(w, "<Error><Code>NoSuchBucket</Code></Error>")
		return
	}
	q := r.URL.Query()
	body, _ := io.ReadAll(r.Body)
	switch {
	case r.Method == http.MethodGet && key == "":
		var names []string
		for k := range f.objects {
			if strings.HasPrefix(k, q.Get("prefix")) {
				names = append(names, k)
			}
		}
		sort.Strings(names)
		fmt.Fprint(w, "<ListBucketResult>")
		for _, n := range names {
			fmt.Fprintf(w, "<Contents><Key>%s</Key><Size>%d</Size></Contents>", n, len(f.objects[n]))
		}
		fmt.Fprint(w, "<IsTruncated>false</IsTruncated></ListBucketResult>")
	case r.Method == http.MethodPost && q.Has("uploads"):
		f.parts["u1"] = map[string][]byte{}
		fmt.Fprint(w, "<InitiateMultipartUploadResult><UploadId>u1</UploadId></InitiateMultipartUploadResult>")
	case r.Method == http.MethodPut && q.Has("partNumber"):
		f.parts[q.Get("uploadId")][q.Get("partNumber")] = body
		w.Header().Set("ETag", `"p`+q.Get("partNumber")+`"`)
	case r.Method == http.MethodPost && q.Has("uploadId"):
		var done struct {
			Parts []struct {
				Number string `xml:"PartNumber"`
				ETag   string `xml:"ETag"`
			} `xml:"Part"`
		}
		_ = xml.Unmarshal(body, &done)
		var all []byte
		for _, p := range done.Parts {
			all = append(all, f.parts[q.Get("uploadId")][p.Number]...)
		}
		f.objects[key] = all
		fmt.Fprint(w, "<CompleteMultipartUploadResult/>")
	case r.Method == http.MethodPut:
		f.puts++
		f.objects[key] = body
	case r.Method == http.MethodGet:
		b, ok := f.objects[key]
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			fmt.Fprint(w, "<Error><Code>NoSuchKey</Code></Error>")
			return
		}
		w.Write(b)
	case r.Method == http.MethodDelete:
		delete(f.objects, key)
		w.WriteHeader(http.StatusNoContent)
	}
}

func TestS3PutsGetsListsAndDeletes(t *testing.T) {
	fake := &fakeS3{objects: map[string][]byte{}, parts: map[string]map[string][]byte{}}
	srv := httptest.NewServer(fake)
	defer srv.Close()
	spec := TargetSpec{Type: "s3", Endpoint: srv.URL, Bucket: "yedek", AccessKey: "AK", SecretKey: "SK", PathStyle: true, Region: "eu-central-1"}
	if err := spec.validate(); err != nil {
		t.Fatal(err)
	}
	s, err := newS3(spec, srv.Client())
	if err != nil {
		t.Fatal(err)
	}
	s.partSize = 1000
	ctx := context.Background()
	dir := t.TempDir()
	small, big := filepath.Join(dir, "small"), filepath.Join(dir, "big")
	os.WriteFile(small, []byte("hello"), 0o600)
	bigData := bytes.Repeat([]byte("0123456789"), 350)
	os.WriteFile(big, bigData, 0o600)

	if err := s.Put(ctx, "serfex/k1/a.tar", small); err != nil {
		t.Fatal(err)
	}
	if err := s.Put(ctx, "serfex/k1/b.tar", big); err != nil {
		t.Fatal(err)
	}
	if fake.puts != 1 || !bytes.Equal(fake.objects["serfex/k1/b.tar"], bigData) {
		t.Fatalf("the big file was not sent in parts (%d single puts)", fake.puts)
	}
	var buf bytes.Buffer
	if err := s.Get(ctx, "serfex/k1/a.tar", &buf); err != nil || buf.String() != "hello" {
		t.Fatalf("get: %q %v", buf.String(), err)
	}
	objs, err := s.List(ctx, "serfex/k1")
	if err != nil || len(objs) != 2 || objs[1].Size != int64(len(bigData)) {
		t.Fatalf("list: %+v %v", objs, err)
	}
	if err := s.Delete(ctx, "serfex/k1/a.tar"); err != nil {
		t.Fatal(err)
	}
	if err := s.Get(ctx, "serfex/k1/a.tar", io.Discard); err == nil || !strings.Contains(err.Error(), "is not on the target") {
		t.Fatalf("get after delete: %v", err)
	}
	// A wrong bucket comes back as the storage's own words.
	s.spec.Bucket = "baska"
	if err := s.Put(ctx, "x", small); err == nil || !strings.Contains(err.Error(), "NoSuchBucket") {
		t.Fatalf("err = %v", err)
	}
}

func TestS3AddressesBucketsByHostUnlessPathStyle(t *testing.T) {
	s, _ := newS3(TargetSpec{Type: "s3", Region: "eu-central-1", Bucket: "yedek", AccessKey: "a", SecretKey: "b"}, nil)
	if u := s.objectURL("serfex/k1/a.tar", nil).String(); u != "https://yedek.s3.eu-central-1.amazonaws.com/serfex/k1/a.tar" {
		t.Fatal(u)
	}
	s, _ = newS3(TargetSpec{Type: "s3", Endpoint: "http://127.0.0.1:9000", Bucket: "yedek", AccessKey: "a", SecretKey: "b", PathStyle: true}, nil)
	if u := s.objectURL("", map[string][]string{"list-type": {"2"}, "prefix": {"serfex/"}}).String(); u != "http://127.0.0.1:9000/yedek?list-type=2&prefix=serfex%2F" {
		t.Fatal(u)
	}
	for _, bad := range []string{"ftp://x", "https://x/y", "https://u:p@x"} {
		if err := (TargetSpec{Type: "s3", Endpoint: bad, Bucket: "yedek", AccessKey: "a", SecretKey: "b"}).validate(); err == nil {
			t.Fatalf("endpoint %q accepted", bad)
		}
	}
}

func TestSFTPListingIsParsed(t *testing.T) {
	out := "sftp> -ls -ln serfex\n" +
		"drwxr-xr-x    2 1000     1000         4096 Oct 19 03:00 serfex/k1\n" +
		"-rw-r--r--    1 1000     1000          512 Oct 19 03:00 serfex/k1/backup.json\n" +
		"-rw-r--r--    1 1000     1000          512 Oct 19 03:00 serfex/../etc/passwd\n"
	got := parseSFTPList(out)
	if len(got) != 2 || !got[0].dir || got[1].name != "serfex/k1/backup.json" || got[1].size != 512 {
		t.Fatalf("%+v", got)
	}
}
