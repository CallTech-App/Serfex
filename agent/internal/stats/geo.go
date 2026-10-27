package stats

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math/big"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Countries come from the five regional Internet registries' public
// "delegated" files: which country each block of addresses was given to.
// No third-party service sees the visitors; the files are downloaded once
// a month and looked up locally. A registration country is not always
// where the visitor sits (cloud providers), which is accurate enough for
// "where do my visitors come from".
var RegistryFiles = []string{
	"https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest",
	"https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest",
	"https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest",
	"https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-extended-latest",
	"https://ftp.afrinic.net/pub/stats/afrinic/delegated-afrinic-extended-latest",
}

// GeoFile is where the built country table is kept.
const GeoFile = "/var/lib/serfex/stats/countries.bin"

// GeoMaxAge is how old the table may get before it is downloaded again.
const GeoMaxAge = 30 * 24 * time.Hour

const geoMagic = "SFXGEO1\n"

type range4 struct {
	start, end uint32
	cc         [2]byte
}

type range6 struct {
	start, end uint64 // the upper 64 bits; registries never hand out smaller blocks
	cc         [2]byte
}

// Geo maps addresses to two-letter country codes.
type Geo struct {
	v4 []range4
	v6 []range6
}

// Country returns the ISO 3166 code of the country an address was
// registered in, or "" when it is unknown (private, reserved, or not in
// the table).
func (g *Geo) Country(ip string) string {
	if g == nil {
		return ""
	}
	a, err := netip.ParseAddr(ip)
	if err != nil {
		return ""
	}
	a = a.Unmap()
	if a.Is4() {
		b := a.As4()
		n := binary.BigEndian.Uint32(b[:])
		i := sort.Search(len(g.v4), func(i int) bool { return g.v4[i].end >= n })
		if i < len(g.v4) && g.v4[i].start <= n {
			return string(g.v4[i].cc[:])
		}
		return ""
	}
	b := a.As16()
	n := binary.BigEndian.Uint64(b[:8])
	i := sort.Search(len(g.v6), func(i int) bool { return g.v6[i].end >= n })
	if i < len(g.v6) && g.v6[i].start <= n {
		return string(g.v6[i].cc[:])
	}
	return ""
}

// Size is how many address blocks the table holds.
func (g *Geo) Size() int {
	if g == nil {
		return 0
	}
	return len(g.v4) + len(g.v6)
}

// ParseRegistry adds the blocks of one delegated file to g; BuildGeo
// sorts them afterwards. Lines that
// are not allocated or assigned blocks (headers, summaries, free space)
// are skipped.
func (g *Geo) ParseRegistry(r io.Reader) error {
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		line := sc.Text()
		if line == "" || line[0] == '#' {
			continue
		}
		f := strings.Split(line, "|")
		if len(f) < 7 || len(f[1]) != 2 || f[1] == "ZZ" {
			continue
		}
		if f[6] != "allocated" && f[6] != "assigned" {
			continue
		}
		cc := [2]byte{upper(f[1][0]), upper(f[1][1])}
		switch f[2] {
		case "ipv4":
			a, err := netip.ParseAddr(f[3])
			n, err2 := strconv.ParseUint(f[4], 10, 64)
			if err != nil || err2 != nil || !a.Is4() || n == 0 {
				continue
			}
			b := a.As4()
			start := uint64(binary.BigEndian.Uint32(b[:]))
			end := start + n - 1
			if end > 0xffffffff {
				continue
			}
			g.v4 = append(g.v4, range4{uint32(start), uint32(end), cc})
		case "ipv6":
			a, err := netip.ParseAddr(f[3])
			bits, err2 := strconv.Atoi(f[4])
			if err != nil || err2 != nil || !a.Is6() || bits < 1 || bits > 64 {
				continue
			}
			b := a.As16()
			start := binary.BigEndian.Uint64(b[:8])
			span := new(big.Int).Lsh(big.NewInt(1), uint(64-bits))
			end := new(big.Int).Add(new(big.Int).SetUint64(start), span)
			end.Sub(end, big.NewInt(1))
			if !end.IsUint64() {
				continue
			}
			g.v6 = append(g.v6, range6{start, end.Uint64(), cc})
		}
	}
	return sc.Err()
}

func upper(b byte) byte {
	if b >= 'a' && b <= 'z' {
		return b - 32
	}
	return b
}

// BuildGeo makes a table from delegated files.
func BuildGeo(files ...io.Reader) (*Geo, error) {
	g := &Geo{}
	for _, f := range files {
		if err := g.ParseRegistry(f); err != nil {
			return nil, err
		}
	}
	g.sort()
	return g, nil
}

// sort orders the blocks and drops ones inside an earlier block, so a
// lookup is a binary search.
func (g *Geo) sort() {
	sort.Slice(g.v4, func(i, j int) bool { return g.v4[i].start < g.v4[j].start })
	sort.Slice(g.v6, func(i, j int) bool { return g.v6[i].start < g.v6[j].start })
	v4 := g.v4[:0]
	for _, r := range g.v4 {
		if len(v4) > 0 && r.start <= v4[len(v4)-1].end {
			continue
		}
		v4 = append(v4, r)
	}
	g.v4 = v4
	v6 := g.v6[:0]
	for _, r := range g.v6 {
		if len(v6) > 0 && r.start <= v6[len(v6)-1].end {
			continue
		}
		v6 = append(v6, r)
	}
	g.v6 = v6
}

// MarshalBinary writes the table in the file format: a magic line, the
// block counts, then fixed-size records.
func (g *Geo) MarshalBinary() ([]byte, error) {
	var b bytes.Buffer
	b.WriteString(geoMagic)
	_ = binary.Write(&b, binary.BigEndian, uint32(len(g.v4)))
	_ = binary.Write(&b, binary.BigEndian, uint32(len(g.v6)))
	for _, r := range g.v4 {
		_ = binary.Write(&b, binary.BigEndian, r.start)
		_ = binary.Write(&b, binary.BigEndian, r.end)
		b.Write(r.cc[:])
	}
	for _, r := range g.v6 {
		_ = binary.Write(&b, binary.BigEndian, r.start)
		_ = binary.Write(&b, binary.BigEndian, r.end)
		b.Write(r.cc[:])
	}
	return b.Bytes(), nil
}

// LoadGeo reads a table written by MarshalBinary.
func LoadGeo(file string) (*Geo, error) {
	data, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	if !bytes.HasPrefix(data, []byte(geoMagic)) || len(data) < len(geoMagic)+8 {
		return nil, errors.New("not a country table")
	}
	p := data[len(geoMagic):]
	n4 := int(binary.BigEndian.Uint32(p))
	n6 := int(binary.BigEndian.Uint32(p[4:]))
	p = p[8:]
	if len(p) != n4*10+n6*18 {
		return nil, errors.New("country table is truncated")
	}
	g := &Geo{v4: make([]range4, n4), v6: make([]range6, n6)}
	for i := range g.v4 {
		g.v4[i] = range4{binary.BigEndian.Uint32(p), binary.BigEndian.Uint32(p[4:]), [2]byte{p[8], p[9]}}
		p = p[10:]
	}
	for i := range g.v6 {
		g.v6[i] = range6{binary.BigEndian.Uint64(p), binary.BigEndian.Uint64(p[8:]), [2]byte{p[16], p[17]}}
		p = p[18:]
	}
	return g, nil
}

// DownloadGeo fetches the delegated files, builds the table and writes it
// to file (atomically). Every registry must answer: a table missing a
// continent would quietly show its visitors as unknown.
func DownloadGeo(ctx context.Context, client *http.Client, urls []string, file string) (*Geo, error) {
	if client == nil {
		client = &http.Client{Timeout: 5 * time.Minute}
	}
	g := &Geo{}
	for _, u := range urls {
		if err := fetchRegistry(ctx, client, u, g); err != nil {
			return nil, err
		}
	}
	if len(g.v4) == 0 {
		return nil, errors.New("the registries listed no addresses")
	}
	g.sort()
	data, _ := g.MarshalBinary()
	if err := os.MkdirAll(filepath.Dir(file), 0o755); err != nil {
		return nil, err
	}
	tmp := file + ".tmp"
	if err := os.WriteFile(tmp, data, 0o644); err != nil {
		return nil, err
	}
	if err := os.Rename(tmp, file); err != nil {
		return nil, err
	}
	return g, nil
}

func fetchRegistry(ctx context.Context, client *http.Client, url string, g *Geo) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("User-Agent", "serfex-agent")
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("%s: %s", url, resp.Status)
	}
	return g.ParseRegistry(resp.Body)
}

// KeepGeo loads the country table into a at once, and downloads it again
// whenever it is missing or older than GeoMaxAge, until ctx ends. A failed
// download is tried again a day later; countries show as unknown until
// the first one succeeds.
func KeepGeo(ctx context.Context, a *Aggregator, file string, urls []string, log *slog.Logger) {
	if g, err := LoadGeo(file); err == nil {
		a.SetGeo(g)
	}
	for {
		fi, err := os.Stat(file)
		if err != nil || time.Since(fi.ModTime()) > GeoMaxAge || !a.HasGeo() {
			dctx, cancel := context.WithTimeout(ctx, 15*time.Minute)
			g, err := DownloadGeo(dctx, nil, urls, file)
			cancel()
			if err != nil {
				log.Warn("downloading the country table", "err", err)
			} else {
				a.SetGeo(g)
				log.Info("country table updated", "blocks", g.Size())
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(24 * time.Hour):
		}
	}
}

// demoRegistry gives the made-up visitors of a dry run their countries.
const demoRegistry = `demo|TR|ipv4|88.224.0.0|1048576|20050419|allocated
demo|TR|ipv4|78.224.0.0|1048576|20050419|allocated
demo|DE|ipv4|5.1.0.0|65536|20120101|allocated
demo|NL|ipv4|37.0.0.0|65536|20120101|allocated
demo|US|ipv4|3.0.0.0|16777216|19880101|allocated
demo|GB|ipv4|2.24.0.0|524288|20100101|allocated
demo|AZ|ipv4|5.191.0.0|65536|20100101|allocated
demo|FR|ipv4|2.224.0.0|1048576|20100101|allocated
demo|RU|ipv4|45.155.205.0|256|20200101|allocated
demo|SC|ipv4|193.42.33.0|256|20200101|allocated
demo|VN|ipv4|103.153.79.0|256|20200101|allocated
`

// DemoGeo is the dry run's country table.
func DemoGeo() *Geo {
	g, _ := BuildGeo(strings.NewReader(demoRegistry))
	return g
}
