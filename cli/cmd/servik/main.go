// Command servik is Servik's command line: sites, domains, DNS, SSL, mail,
// databases, backups and services, and deploying and running Laravel
// applications, on the server itself or from a developer's computer.
package main

import (
	"os"

	"github.com/winchester37/servik/cli/internal/app"
)

func main() {
	os.Exit(app.Main(os.Args[1:], app.System()))
}
