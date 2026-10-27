// Command serfex is Serfex's command line: sites, domains, DNS, SSL, mail,
// databases, backups and services, and deploying and running Laravel
// applications, on the server itself or from a developer's computer.
package main

import (
	"os"

	"github.com/calltech-app/serfex/cli/internal/app"
)

func main() {
	os.Exit(app.Main(os.Args[1:], app.System()))
}
