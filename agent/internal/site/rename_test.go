package site

import (
	"context"
	"os"
	"testing"
)

func renameTo(domain string) RenameSpec {
	next := spec
	next.Domain = domain
	next.Aliases = []string{"www." + domain}
	return RenameSpec{From: spec.Domain, Site: next}
}

func TestRenameThenNewSiteOnTheOldName(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Rename(ctx, renameTo("kahve.com")); err != nil {
		t.Fatal(err)
	}
	old := "/home/kahveatolyesi/web/kahveatolyesi.com.tr"
	if target, err := os.Readlink(sim.Path(old)); err != nil || target != "kahve.com" {
		t.Fatalf("old folder link = %q, %v", target, err)
	}
	// A new site may take the old name: it gets a folder of its own.
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if fi, err := os.Lstat(sim.Path(old)); err != nil || !fi.IsDir() {
		t.Fatalf("the old name did not get its own folder: %v", err)
	}
	if !exists(sim, "/home/kahveatolyesi/web/kahve.com/public_html/index.html") {
		t.Error("the renamed site lost its files")
	}
}

func TestRenameRefusesWhatIsNotThere(t *testing.T) {
	m, _ := newTest(t)
	if _, err := m.Rename(context.Background(), renameTo("kahve.com")); err == nil {
		t.Fatal("a site that does not exist was renamed")
	}
	if _, err := m.Apply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Rename(context.Background(), renameTo(spec.Domain)); err == nil {
		t.Fatal("a rename to the same name was accepted")
	}
}

func TestDeleteRemovesRenameLinks(t *testing.T) {
	m, sim := newTest(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, spec); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Rename(ctx, renameTo("kahve.com")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Delete(ctx, DeleteSpec{Domain: "kahve.com", User: spec.User, RemoveFiles: true}); err != nil {
		t.Fatal(err)
	}
	if exists(sim, "/home/kahveatolyesi/web/kahveatolyesi.com.tr") {
		t.Error("the link of the old name outlived the site")
	}
}
