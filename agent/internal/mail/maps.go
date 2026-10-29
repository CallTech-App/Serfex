package mail

import (
	"fmt"
	"sort"
	"strings"

	"github.com/calltech-app/serfex/agent/internal/txn"
)

// maps are the files rebuilt from the stored domains on every change.
type maps struct {
	// Postfix lookup tables, by file name under /etc/postfix/serfex.
	postfix map[string][]byte
	// users is Dovecot's passwd-file.
	users []byte
	// sieve holds the autoreply scripts by path.
	sieve map[string][]byte
	// dkim is Rspamd's dkim_signing.conf.
	dkim []byte
	// senders limits the senders of mail sent from this server.
	senders senderMaps
}

// suspendedHash stands in for a suspended mailbox's password: a crypt
// hash no password can match.
const suspendedHash = "{CRYPT}*"

func mapHeader(what string) string {
	return "# " + txn.Marker + ". Manual changes are overwritten.\n# " + what + "\n"
}

// sieveScript returns the path of a mailbox's autoreply script.
func sieveScript(domain, name string) string {
	return sieveDir + "/" + domain + "/" + name + ".sieve"
}

func dkimKey(domain string) string { return dkimDir + "/" + domain + ".key" }

// buildMaps derives every map from the stored domains.
func buildMaps(domains []DomainSpec) maps {
	var (
		doms, boxes, aliases, senders, users strings.Builder
		dkim, suspended                      strings.Builder
	)
	doms.WriteString(mapHeader("Mail domains (virtual_mailbox_domains)."))
	boxes.WriteString(mapHeader("Mailboxes (virtual_mailbox_maps)."))
	aliases.WriteString(mapHeader("Forwarders, mailbox forwarding and catch-all addresses (virtual_alias_maps)."))
	senders.WriteString(mapHeader("Which mailbox may send as which address (smtpd_sender_login_maps)."))
	users.WriteString(mapHeader("Mailboxes for Dovecot: address:password hash::::::extra fields."))
	dkim.WriteString(mapHeader("DKIM signing keys of the mail domains."))
	suspended.WriteString(mapHeader("Domains whose incoming mail is deferred: suspended, or their owner's disk quota is full (check_recipient_access)."))
	dkim.WriteString("use_esld = false;\ntry_fallback = false;\nallow_username_mismatch = false;\ndomain {\n")

	out := maps{postfix: map[string][]byte{}, sieve: map[string][]byte{}}
	for _, d := range domains {
		fmt.Fprintf(&doms, "%s OK\n", d.Domain)
		switch {
		case d.Suspended:
			fmt.Fprintf(&suspended, "%s 450 4.2.1 Mailbox temporarily unavailable\n", d.Domain)
		case d.DiskFull:
			fmt.Fprintf(&suspended, "%s 452 4.2.2 Mailbox full: the account's disk quota is used up\n", d.Domain)
		}
		fmt.Fprintf(&dkim, "  %q {\n    selector = %q;\n    path = %q;\n  }\n", d.Domain, DKIMSelector, dkimKey(d.Domain))

		// Mailboxes that receive mail for an address, for sender logins
		// and autoreply addresses.
		owners := map[string][]string{}
		for _, a := range d.Aliases {
			for _, to := range a.To {
				if strings.HasSuffix(to, "@"+d.Domain) && d.mailbox(strings.TrimSuffix(to, "@"+d.Domain)) != nil {
					owners[d.address(a.Name)] = append(owners[d.address(a.Name)], to)
				}
			}
		}

		for _, b := range d.Mailboxes {
			addr := d.address(b.Name)
			fmt.Fprintf(&boxes, "%s %s/%s/\n", addr, d.Domain, b.Name)
			// Every mailbox maps to itself so a catch-all never swallows it.
			dest := []string{addr}
			if len(b.ForwardTo) > 0 {
				dest = append([]string(nil), b.ForwardTo...)
				if b.KeepCopy {
					dest = append([]string{addr}, dest...)
				}
			}
			fmt.Fprintf(&aliases, "%s %s\n", addr, strings.Join(dest, ", "))
			fmt.Fprintf(&senders, "%s %s\n", addr, addr)

			fields := []string{}
			hash := b.PasswordHash
			if d.Suspended {
				// No password matches while suspended, so IMAP, POP3 and
				// SMTP AUTH all fail (nologin alone only stops the IMAP and
				// POP3 login processes). Lifting it writes the hash back.
				hash = suspendedHash
				fields = append(fields, "nologin=y")
			}
			if b.QuotaMB > 0 {
				fields = append(fields, fmt.Sprintf("userdb_quota_rule=*:storage=%dM", b.QuotaMB))
			}
			switch {
			case b.Autoreply != nil:
				path := sieveScript(d.Domain, b.Name)
				var mine []string
				for a, o := range owners {
					for _, x := range o {
						if x == addr {
							mine = append(mine, a)
						}
					}
				}
				sort.Strings(mine)
				out.sieve[path] = autoreplyScript(addr, mine, *b.Autoreply, d.SpamFilter)
				fields = append(fields, "userdb_sieve_before="+path)
			case d.SpamFilter:
				fields = append(fields, "userdb_sieve_before="+spamSieve)
			}
			fmt.Fprintf(&users, "%s:%s::::::%s\n", addr, hash, strings.Join(fields, " "))
		}
		for _, a := range d.Aliases {
			fmt.Fprintf(&aliases, "%s %s\n", d.address(a.Name), strings.Join(a.To, ", "))
			if o := owners[d.address(a.Name)]; len(o) > 0 {
				fmt.Fprintf(&senders, "%s %s\n", d.address(a.Name), strings.Join(o, ", "))
			}
		}
		if len(d.CatchAll) > 0 {
			fmt.Fprintf(&aliases, "@%s %s\n", d.Domain, strings.Join(d.CatchAll, ", "))
		}
	}
	dkim.WriteString("}\n")

	out.postfix["domains"] = []byte(doms.String())
	out.postfix["mailboxes"] = []byte(boxes.String())
	out.postfix["aliases"] = []byte(aliases.String())
	out.postfix["senders"] = []byte(senders.String())
	out.postfix["suspended"] = []byte(suspended.String())
	out.users = []byte(users.String())
	out.dkim = []byte(dkim.String())
	return out
}

// sieveString quotes s as a Sieve string.
func sieveString(s string) string {
	return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(s) + `"`
}

// autoreplyScript answers mail once a day per sender until the end date,
// after filing spam (which never gets an answer).
func autoreplyScript(addr string, aliases []string, a Autoreply, spam bool) []byte {
	var b strings.Builder
	b.WriteString("# " + txn.Marker + ". Manual changes are overwritten.\n")
	b.WriteString("# Autoreply of " + addr + ".\n")
	b.WriteString(`require ["fileinto", "vacation", "date", "relational"];` + "\n")
	b.WriteString(`if header :is "X-Spam" "Yes" {` + "\n")
	if spam {
		b.WriteString("  fileinto \"Junk\";\n")
	}
	b.WriteString("  stop;\n}\n")
	addrs := []string{sieveString(addr)}
	for _, x := range aliases {
		addrs = append(addrs, sieveString(x))
	}
	subject := a.Subject
	if subject == "" {
		subject = "Otomatik yanıt"
	}
	vacation := fmt.Sprintf("vacation :days 1 :addresses [%s] :subject %s %s;\n", strings.Join(addrs, ", "), sieveString(subject), sieveString(a.Body))
	if a.Until != "" {
		b.WriteString(fmt.Sprintf("if currentdate :value \"le\" \"date\" %s {\n  %s}\n", sieveString(a.Until), vacation))
	} else {
		b.WriteString(vacation)
	}
	return []byte(b.String())
}
