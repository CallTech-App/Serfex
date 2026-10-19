package backup

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"
)

// s3Target talks to S3-compatible storage (AWS S3, Backblaze B2, Wasabi,
// Hetzner Object Storage, MinIO, ...) with Signature Version 4. Files up
// to partSize go in one request; bigger ones are sent as a multipart
// upload, part by part, so a failed part is sent again on its own.
type s3Target struct {
	spec     TargetSpec
	endpoint *url.URL
	client   *http.Client
	now      func() time.Time
	partSize int64
}

const (
	s3PartSize   = 64 << 20
	s3MaxParts   = 9000
	s3PartTries  = 3
	emptySHA256  = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
	s3TimeFormat = "20060102T150405Z"
)

func newS3(spec TargetSpec, client *http.Client) (*s3Target, error) {
	ep, err := spec.endpointURL()
	if err != nil {
		return nil, err
	}
	if client == nil {
		client = &http.Client{Timeout: 30 * time.Minute}
	}
	return &s3Target{spec: spec, endpoint: ep, client: client, now: time.Now, partSize: s3PartSize}, nil
}

func (s *s3Target) region() string {
	if s.spec.Region != "" {
		return s.spec.Region
	}
	return "us-east-1"
}

// objectURL is the URL of key (or of the bucket for an empty key).
func (s *s3Target) objectURL(key string, query url.Values) *url.URL {
	u := *s.endpoint
	escaped := s3Escape(key)
	if s.spec.PathStyle {
		u.Path = "/" + s.spec.Bucket
		if key != "" {
			u.Path += "/" + key
		}
		u.RawPath = "/" + s.spec.Bucket
		if key != "" {
			u.RawPath += "/" + escaped
		}
	} else {
		u.Host = s.spec.Bucket + "." + u.Host
		u.Path = "/" + key
		u.RawPath = "/" + escaped
	}
	u.RawQuery = canonicalQuery(query)
	return &u
}

// s3Escape URI-encodes every byte but the unreserved ones, keeping '/'.
func s3Escape(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c >= 'A' && c <= 'Z' || c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '-' || c == '_' || c == '.' || c == '~' || c == '/' {
			b.WriteByte(c)
			continue
		}
		fmt.Fprintf(&b, "%%%02X", c)
	}
	return b.String()
}

func canonicalQuery(q url.Values) string {
	if len(q) == 0 {
		return ""
	}
	keys := make([]string, 0, len(q))
	for k := range q {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var parts []string
	for _, k := range keys {
		vs := append([]string(nil), q[k]...)
		sort.Strings(vs)
		for _, v := range vs {
			parts = append(parts, strings.ReplaceAll(s3Escape(k), "/", "%2F")+"="+strings.ReplaceAll(s3Escape(v), "/", "%2F"))
		}
	}
	return strings.Join(parts, "&")
}

func hmacSHA256(key []byte, data string) []byte {
	h := hmac.New(sha256.New, key)
	h.Write([]byte(data))
	return h.Sum(nil)
}

// sign adds the SigV4 Authorization header for a payload with the given
// SHA-256.
func (s *s3Target) sign(req *http.Request, payloadHash string) {
	t := s.now().UTC()
	stamp := t.Format(s3TimeFormat)
	day := stamp[:8]
	req.Header.Set("x-amz-date", stamp)
	req.Header.Set("x-amz-content-sha256", payloadHash)

	headers := map[string]string{"host": req.URL.Host}
	for k := range req.Header {
		lk := strings.ToLower(k)
		if strings.HasPrefix(lk, "x-amz-") || lk == "content-type" || lk == "content-md5" {
			headers[lk] = strings.TrimSpace(req.Header.Get(k))
		}
	}
	names := make([]string, 0, len(headers))
	for k := range headers {
		names = append(names, k)
	}
	sort.Strings(names)
	var canonHeaders strings.Builder
	for _, k := range names {
		canonHeaders.WriteString(k + ":" + headers[k] + "\n")
	}
	signed := strings.Join(names, ";")
	path := req.URL.RawPath
	if path == "" {
		path = req.URL.EscapedPath()
	}
	if path == "" {
		path = "/"
	}
	canon := strings.Join([]string{req.Method, path, req.URL.RawQuery, canonHeaders.String(), signed, payloadHash}, "\n")
	scope := day + "/" + s.region() + "/s3/aws4_request"
	sum := sha256.Sum256([]byte(canon))
	toSign := "AWS4-HMAC-SHA256\n" + stamp + "\n" + scope + "\n" + hex.EncodeToString(sum[:])
	key := hmacSHA256([]byte("AWS4"+s.spec.SecretKey), day)
	key = hmacSHA256(key, s.region())
	key = hmacSHA256(key, "s3")
	key = hmacSHA256(key, "aws4_request")
	sig := hex.EncodeToString(hmacSHA256(key, toSign))
	req.Header.Set("Authorization", fmt.Sprintf("AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s", s.spec.AccessKey, scope, signed, sig))
}

// s3Error is an error answer from the storage.
type s3Error struct {
	Status  int
	Code    string `xml:"Code"`
	Message string `xml:"Message"`
}

func (e *s3Error) Error() string {
	switch {
	case e.Code != "" && e.Message != "":
		return fmt.Sprintf("the storage answered %d %s: %s", e.Status, e.Code, e.Message)
	case e.Code != "":
		return fmt.Sprintf("the storage answered %d %s", e.Status, e.Code)
	}
	return fmt.Sprintf("the storage answered HTTP %d", e.Status)
}

// do sends a signed request; body is read from r (size bytes, payload
// SHA-256 hash). The response is returned for 2xx answers.
func (s *s3Target) do(ctx context.Context, method, key string, query url.Values, r io.Reader, size int64, hash string, header http.Header) (*http.Response, error) {
	if hash == "" {
		hash = emptySHA256
	}
	req, err := http.NewRequestWithContext(ctx, method, s.objectURL(key, query).String(), r)
	if err != nil {
		return nil, err
	}
	// NewRequest decodes the escaped path; send it as signed.
	req.URL = s.objectURL(key, query)
	req.ContentLength = size
	if r == nil {
		req.Body = http.NoBody
	}
	for k, vs := range header {
		for _, v := range vs {
			req.Header.Add(k, v)
		}
	}
	s.sign(req, hash)
	resp, err := s.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("connecting to %s: %w", req.URL.Host, err)
	}
	if resp.StatusCode/100 != 2 {
		defer resp.Body.Close()
		e := &s3Error{Status: resp.StatusCode}
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		_ = xml.Unmarshal(b, e)
		return nil, e
	}
	return resp, nil
}

func sha256Section(f *os.File, off, n int64) (string, error) {
	h := sha256.New()
	if _, err := io.Copy(h, io.NewSectionReader(f, off, n)); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func (s *s3Target) Put(ctx context.Context, name, file string) error {
	if err := validName(name); err != nil {
		return err
	}
	f, err := os.Open(file)
	if err != nil {
		return err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return err
	}
	size := fi.Size()
	part := max(s.partSize, (size+s3MaxParts-1)/s3MaxParts)
	if size <= part {
		hash, err := sha256Section(f, 0, size)
		if err != nil {
			return err
		}
		resp, err := s.do(ctx, http.MethodPut, name, nil, io.NewSectionReader(f, 0, size), size, hash, nil)
		if err != nil {
			return err
		}
		resp.Body.Close()
		return nil
	}
	return s.multipart(ctx, name, f, size, part)
}

func (s *s3Target) multipart(ctx context.Context, name string, f *os.File, size, partSize int64) (err error) {
	resp, err := s.do(ctx, http.MethodPost, name, url.Values{"uploads": {""}}, nil, 0, "", nil)
	if err != nil {
		return err
	}
	var start struct {
		UploadID string `xml:"UploadId"`
	}
	err = xml.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&start)
	resp.Body.Close()
	if err != nil || start.UploadID == "" {
		return errors.New("the storage did not start a multipart upload")
	}
	uploadQuery := url.Values{"uploadId": {start.UploadID}}
	defer func() {
		if err != nil {
			if r, aerr := s.do(context.WithoutCancel(ctx), http.MethodDelete, name, uploadQuery, nil, 0, "", nil); aerr == nil {
				r.Body.Close()
			}
		}
	}()
	type completed struct {
		Number int    `xml:"PartNumber"`
		ETag   string `xml:"ETag"`
	}
	var parts []completed
	for n, off := 1, int64(0); off < size; n, off = n+1, off+partSize {
		length := min(partSize, size-off)
		hash, err := sha256Section(f, off, length)
		if err != nil {
			return err
		}
		q := url.Values{"partNumber": {strconv.Itoa(n)}, "uploadId": {start.UploadID}}
		var etag string
		for try := 1; ; try++ {
			resp, err := s.do(ctx, http.MethodPut, name, q, io.NewSectionReader(f, off, length), length, hash, nil)
			if err == nil {
				etag = resp.Header.Get("ETag")
				resp.Body.Close()
				break
			}
			if try == s3PartTries || ctx.Err() != nil {
				return fmt.Errorf("part %d: %w", n, err)
			}
			time.Sleep(time.Duration(try) * 2 * time.Second)
		}
		parts = append(parts, completed{Number: n, ETag: etag})
	}
	body, _ := xml.Marshal(struct {
		XMLName xml.Name    `xml:"CompleteMultipartUpload"`
		Parts   []completed `xml:"Part"`
	}{Parts: parts})
	sum := sha256.Sum256(body)
	resp, err = s.do(ctx, http.MethodPost, name, uploadQuery, bytes.NewReader(body), int64(len(body)), hex.EncodeToString(sum[:]), http.Header{"Content-Type": {"application/xml"}})
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	// An error can come with a 200 answer here.
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if bytes.Contains(b, []byte("<Error>")) {
		e := &s3Error{Status: resp.StatusCode}
		_ = xml.Unmarshal(b, e)
		return e
	}
	return nil
}

func (s *s3Target) Get(ctx context.Context, name string, w io.Writer) error {
	if err := validName(name); err != nil {
		return err
	}
	resp, err := s.do(ctx, http.MethodGet, name, nil, nil, 0, "", nil)
	if err != nil {
		var se *s3Error
		if errors.As(err, &se) && se.Status == http.StatusNotFound {
			return fmt.Errorf("%s is not on the target", name)
		}
		return err
	}
	defer resp.Body.Close()
	n, err := io.Copy(w, resp.Body)
	if err == nil && resp.ContentLength >= 0 && n != resp.ContentLength {
		err = io.ErrUnexpectedEOF
	}
	return err
}

func (s *s3Target) Delete(ctx context.Context, name string) error {
	if err := validName(name); err != nil {
		return err
	}
	resp, err := s.do(ctx, http.MethodDelete, name, nil, nil, 0, "", nil)
	if err != nil {
		var se *s3Error
		if errors.As(err, &se) && se.Status == http.StatusNotFound {
			return nil
		}
		return err
	}
	resp.Body.Close()
	return nil
}

func (s *s3Target) List(ctx context.Context, dir string) ([]Object, error) {
	prefix := ""
	if dir != "" {
		if err := validName(dir); err != nil {
			return nil, err
		}
		prefix = dir + "/"
	}
	var out []Object
	token := ""
	for page := 0; page < 1000; page++ {
		q := url.Values{"list-type": {"2"}, "max-keys": {"1000"}}
		if prefix != "" {
			q.Set("prefix", prefix)
		}
		if token != "" {
			q.Set("continuation-token", token)
		}
		resp, err := s.do(ctx, http.MethodGet, "", q, nil, 0, "", nil)
		if err != nil {
			return nil, err
		}
		var res struct {
			Contents []struct {
				Key  string `xml:"Key"`
				Size int64  `xml:"Size"`
			} `xml:"Contents"`
			Truncated bool   `xml:"IsTruncated"`
			Next      string `xml:"NextContinuationToken"`
		}
		err = xml.NewDecoder(io.LimitReader(resp.Body, 64<<20)).Decode(&res)
		resp.Body.Close()
		if err != nil {
			return nil, fmt.Errorf("reading the file list: %w", err)
		}
		for _, c := range res.Contents {
			out = append(out, Object{Name: c.Key, Size: c.Size})
		}
		if !res.Truncated || res.Next == "" {
			return out, nil
		}
		token = res.Next
	}
	return out, nil
}
