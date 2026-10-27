// Package config keeps the panels the command line is signed in to: one
// profile per panel (or per application token), and which one is used
// when --profile does not say.
//
// The file is SERFEX_CONFIG, or serfex/config.json in the user's
// configuration folder (~/.config on Linux). It holds keys, so it is
// written for its owner only (0600).
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
)

// Profile is one signed-in panel.
type Profile struct {
	// URL is the panel's address, https://panel.example.com:8443.
	URL string `json:"url"`
	// Token is a command line key (sfx_panel_…) or an application token (sfx_…).
	Token string `json:"token"`
	// Fingerprint pins the panel's certificate (SHA-256, hex) when it is
	// not one the computer trusts, such as the panel's own first one.
	Fingerprint string `json:"fingerprint,omitempty"`
	// Connect is the address to dial instead of the URL's (on the server:
	// 127.0.0.1:8443).
	Connect string `json:"connect,omitempty"`
	// Kind is "key" (a person) or "token" (one application).
	Kind string `json:"kind,omitempty"`
	// Name and Email are the person the key acts as.
	Name  string `json:"name,omitempty"`
	Email string `json:"email,omitempty"`
	// Account is admin, customer, member or token.
	Account string `json:"account,omitempty"`
	// App is the application a token belongs to, or the default -a.
	App string `json:"app,omitempty"`
}

// Config is the whole file.
type Config struct {
	Current  string              `json:"current,omitempty"`
	Profiles map[string]*Profile `json:"profiles"`

	path string
}

// Path is where the file lives.
func Path(getenv func(string) string) (string, error) {
	if p := getenv("SERFEX_CONFIG"); p != "" {
		return p, nil
	}
	dir := getenv("XDG_CONFIG_HOME")
	if dir == "" {
		d, err := os.UserConfigDir()
		if err != nil {
			return "", err
		}
		dir = d
	}
	return filepath.Join(dir, "serfex", "config.json"), nil
}

// Load reads the file; a missing file is an empty configuration.
func Load(path string) (*Config, error) {
	c := &Config{Profiles: map[string]*Profile{}, path: path}
	data, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return c, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(data, c); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if c.Profiles == nil {
		c.Profiles = map[string]*Profile{}
	}
	c.path = path
	return c, nil
}

// File is the path the configuration was loaded from.
func (c *Config) File() string { return c.path }

// Save writes the file atomically, readable only by its owner.
func (c *Config) Save() error {
	if err := os.MkdirAll(filepath.Dir(c.path), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(c.path), ".config-*.json")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(append(data, '\n')); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), c.path)
}

// Names lists the profiles in order.
func (c *Config) Names() []string {
	names := make([]string, 0, len(c.Profiles))
	for n := range c.Profiles {
		names = append(names, n)
	}
	sort.Strings(names)
	return names
}

// Get returns the profile called name, or the current one for "".
func (c *Config) Get(name string) (string, *Profile) {
	if name == "" {
		name = c.Current
		if name == "" && len(c.Profiles) == 1 {
			name = c.Names()[0]
		}
	}
	return name, c.Profiles[name]
}

// Put stores p as name and makes it the current profile.
func (c *Config) Put(name string, p *Profile) {
	c.Profiles[name] = p
	c.Current = name
}

// Remove forgets name; the current profile moves to another one, if any.
func (c *Config) Remove(name string) {
	delete(c.Profiles, name)
	if c.Current == name {
		c.Current = ""
		if names := c.Names(); len(names) > 0 {
			c.Current = names[0]
		}
	}
}
