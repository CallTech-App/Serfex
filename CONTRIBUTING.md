# Contributing to Servik

Thanks for your interest in Servik. Bug reports, ideas, translations,
documentation and code are all welcome. Please read the
[code of conduct](CODE_OF_CONDUCT.md) first, and report security problems
privately as [SECURITY.md](SECURITY.md) describes.

## Before you start

- **Bugs and ideas:** open an issue with the template. Search first; someone
  may have reported it already.
- **Larger changes:** open an issue to talk about it before writing much
  code, so we can agree on the approach.
- **Development setup:** see [docs/development.md](docs/development.md), and
  [docs/architecture.md](docs/architecture.md) for how the pieces fit.

## Ground rules

- **The panel never runs as root.** Anything that needs root belongs in the
  agent, exposed as a narrowly scoped JSON-RPC method that validates its
  input. Do not add generic "run this command" methods.
- **The agent only touches what it owns.** Files it writes start with
  `Managed by Servik`; it never overwrites or deletes anything without that
  mark. Work inside a customer's home runs as that customer.
- **No SQLite for the panel.** The panel uses PostgreSQL, including in tests.
- **Panel services stay separate from customer services.** Do not reuse the
  panel's PHP, web server or database for customer sites.
- **Customers see only their own data.** New hosting pages and API endpoints
  use the existing access checks (`Access`, `visibleTo()`, the API
  middleware); add a test that another customer is refused.
- **Every change can be explained and, where possible, undone.** Follow the
  existing patterns: a confirmation for anything destructive, an activity
  log entry, Undo for 10 minutes where it makes sense.
- **UI follows the mockups in [`design/`](design)** and the rules in
  `design/mockup/kaynak/src/GUIDE.md`: one job per page, a list first then a
  detail, colour only for status. Workflows may be inspired by other panels,
  but names, layouts, icons and wording must be Servik's own.

## Workflow

1. Fork the repository and create a branch from `main`.
2. Make your change with tests.
3. Run the checks below.
4. Open a pull request with the template: what changed, why, how you tested
   it, and screenshots for UI changes.

## Checks

Panel:

```sh
cd panel
vendor/bin/pint --test
php artisan servik:lang-check --agent=../agent
php artisan test
```

Agent:

```sh
cd agent
gofmt -l .
go vet ./...
go test ./...
```

Command line:

```sh
cd cli
gofmt -l .
go vet ./...
go test ./...
```

The command line's tests include the check that `docs/cli.md` and
`docs/tr/cli.md` match the commands; after changing a command or an option,
regenerate them with `go test ./internal/app -run TestReferenceIsCurrent -update`.

CI also runs real-server jobs that change the machine (`-tags realhost`).
Run those only on a throwaway server or VM.

## Text and translations

- Panel text is written in Turkish inside `__()`, and its English goes in
  `panel/lang/en.json`. `servik:lang-check` fails on missing, unused or
  untranslated text, and on Turkish written straight into a view.
- The agent and the command line write messages in Turkish inside `T()`,
  with English in `internal/i18n/catalog.go`; their tests enforce the same
  rules.
- Sentences with markup in them use `<x-t text="…">`, never concatenation.

Improvements to the English or Turkish wording are very welcome, as are
translations into other languages (a new `panel/lang/<code>.json` and the Go
catalogs).

## Code style

- PHP follows Laravel's conventions, enforced by Laravel Pint.
- Go code is formatted with `gofmt` and checked with `go vet`. The command
  line uses only the standard library.
- Code, comments, commit messages and pull requests are written in English.
- Match the surrounding code: its naming, comment density and idioms.

## Documentation

User documentation lives in [`docs/`](docs) in English, with Turkish
versions in `docs/tr/` for installation, the customer guide and the command
line. Update the relevant page in the same pull request as the change.

## License

By contributing, you agree that your contributions are licensed under the
[AGPL-3.0](LICENSE), like the rest of Servik.
