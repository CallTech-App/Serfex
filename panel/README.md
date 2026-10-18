# Servik panel

The Laravel application behind the Servik web UI. See the
[repository README](../README.md) for setup, and [CONTRIBUTING.md](../CONTRIBUTING.md)
for the checks to run before opening a pull request.

Key pieces:

- `app/Services/Agent/AgentClient.php`: JSON-RPC 2.0 client for `servik-agent`.
- `config/servik.php`: agent socket path and timeouts (`SERVIK_AGENT_SOCKET`, `SERVIK_AGENT_TIMEOUT`, `SERVIK_AGENT_JOB_TIMEOUT`).
- `app/Models/Site.php`, `app/Models/SystemUser.php`: a site belongs to a Linux account; an account can own several sites. Customers and plans will own accounts in a later milestone.
- `app/Jobs/ProvisionSite.php`, `app/Jobs/DeleteSite.php`: queued jobs that call the agent (`site.apply`, `site.delete`) and record the result on the site. Run `php artisan queue:work`.
- `app/Livewire/Sites/`: the Siteler list (with the "Site ekle" form) and the site detail page.
- `app/Console/Commands/CreateAdmin.php`: `php artisan servik:admin` creates or resets the single administrator account.
- `resources/views/components/`: Blade layout and shared components. Colours come from the design tokens in `resources/css/app.css`, copied from the approved mockup in `design/`.
- `resources/icons/`: the Lucide icons the UI uses (ISC licence), inlined by `<x-icon name="..."/>`.
