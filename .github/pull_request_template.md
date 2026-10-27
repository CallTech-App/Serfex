## What changed

<!-- What does this pull request do, and why? Link the issue it closes. -->

## How it was tested

<!-- The commands you ran and what you checked by hand. -->

- [ ] `vendor/bin/pint --test` and `php artisan test` in `panel/`
- [ ] `php artisan serfex:lang-check --agent=../agent` in `panel/` (any new or changed text has English)
- [ ] `gofmt -l .`, `go vet ./...` and `go test ./...` in `agent/` and `cli/`
- [ ] Changed a command or option: `go test ./internal/app -run TestReferenceIsCurrent -update` in `cli/`

## Screenshots

<!-- For changes to the panel: before and after, in Turkish or English. -->
