# Contributing to Servik

Thanks for your interest in Servik. The project is in early development, so
expect things to move quickly.

## Ground rules

- **The panel never runs as root.** Anything that needs root belongs in the
  agent, exposed as a narrowly scoped JSON-RPC method. Do not add generic
  "run this command" methods.
- **No SQLite.** The panel uses PostgreSQL, including in tests.
- **Panel services stay separate from customer services.** Do not reuse the
  panel's PHP, web server, database or Redis for customer sites.
- **UI follows the mockups in [`design/`](design)** and the rules in
  `design/mockup/kaynak/src/GUIDE.md`. Workflows may be inspired by other
  panels, but names, layouts, icons and wording must be Servik's own.

## Workflow

1. Fork the repository and create a branch from `main`.
2. Make your change with tests.
3. Run the checks below.
4. Open a pull request describing what changed and why.

## Checks

Panel:

```sh
cd panel
vendor/bin/pint --test
php artisan test
```

Agent:

```sh
cd agent
gofmt -l .
go vet ./...
go test ./...
```

## Code style

- PHP follows Laravel's conventions, enforced by Laravel Pint.
- Go code is formatted with `gofmt` and checked with `go vet`.
- Code, comments, commit messages and pull requests are written in English.
  The panel's user interface is Turkish first.

## License

By contributing, you agree that your contributions are licensed under the
[AGPL-3.0](LICENSE).
