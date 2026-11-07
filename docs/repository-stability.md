# Repository stability

Xorgate's maintenance configuration keeps dependency changes reviewable and runs the existing application acceptance checks against the committed inputs. The canonical check remains **Docker foundation** in **Foundation**. It exercises Xorgate's application; it does not establish correctness or coverage of managed projects.

## What runs automatically

| Mechanism | Trigger | Behavior |
| --- | --- | --- |
| Foundation | Pull requests, pushes to master/dev, manual dispatch | Production boot, locked setup, PHP/configuration/Twig lint, PHPUnit, native development diagnostics, UI/MCP/CLI and worker/artifact persistence checks |
| Composer updates | Monday at 09:00 America/Port-au-Prince | One grouped version-update PR for direct and indirect dependencies within committed manifest constraints |
| Actions updates | Same weekly cadence | One grouped version-update PR; full commit-SHA references remain reviewable |
| FrankenPHP updates | Same weekly cadence | Updates to the PHP 8.5 channel within FrankenPHP major 1 |
| PostgreSQL updates | Same weekly cadence | Updates within PostgreSQL major 17 and the trixie variant |

The update configuration lives in [dependabot.yml](../.github/dependabot.yml). It caps open version-update PRs at two each for Composer/Actions and one each for FrankenPHP/PostgreSQL. These caps are limits, not a target number of PRs. All update PRs run Foundation. Merging remains an owner action; there is no auto-merge workflow.

Foundation has no cron trigger. The weekly updater checks releases; it does not establish that an unchanged checkout still builds on that date. Run Foundation manually when that evidence is needed. New PR runs supersede older runs for the same PR; a running push/manual verification is allowed to finish.

## Required GitHub setting

**Repository files do not activate merge protection.** The existing live ruleset must also require the check.

At the initial inspection on 2026-11-07, [master default, ruleset 24964555](https://github.com/xormania/xorgate/rules/24964555) was active with PR, deletion, and force-push rules, but no required status check. The check's exact name and GitHub Actions source were verified from [the successful master run](https://github.com/xormania/xorgate/actions/runs/38644077987/job/116126503351).

After a fresh successful PR run, edit that ruleset:

1. Enable **Require status checks to pass**.
2. Add **Docker foundation**, selecting **GitHub Actions** as its source.
3. Enable **Require branches to be up to date before merging**.
4. Keep the existing PR requirement, zero required approvals, resolved review threads, merge method, deletion/force-push rules, and owner bypass.

[required-status-checks.json](../.github/required-status-checks.json) is the exact additional REST rule, with integration ID `15368`. An administration-capable client can read the current ruleset and append/replace that rule in its complete `rules` array, then update ruleset `24964555`. Do not send the single rule as a replacement for the entire array: that would discard existing rules. Read back the live result after applying it. This JSON file records the intended setting; it does not apply it.

The strict check setting requires another update/build after the base branch changes. It avoids accepting a result tested only against an older base. The owner's existing bypass remains an explicit escape hatch, including for software authenticated as that owner. A check bound to GitHub Actions identifies the provider, not a particular workflow; review workflow changes through the same PR process.

Keep the job unconditional, retain the pull-request trigger, and avoid path skips or `continue-on-error` on substantive checks. Do not rename **Docker foundation** without changing the required check configuration.

## Repeatable inputs

Normal CI requires tracked `composer.json`, `composer.lock`, `symfony.lock`, and `importmap.php`; validates the Composer lock; and calls `bin/setup` without `--resolve`. The intentional maintainer update path remains `bin/setup --resolve`.

The workflow uses Ubuntu 24.04, the release family used by the previous green baseline. This pins the OS release label, not GitHub's complete runner image. The PHP base, xorder CLI/Serena images, Actions, and UXor recipe revision already use immutable references. Composer's executable is fixed at 2.10.3, the version observed in the green baseline.

After verification, CI rejects changed tracked dependency/recipe/configuration inputs and new untracked files under `assets`, `templates`, or `config`. Runtime output, Symfony's generated IDE helper at `config/reference.php`, and the generated local environment file remain ignored. An intentional installed recipe or configuration change must be reviewed and committed.

The PostgreSQL image is fixed in `compose.yaml` to `postgres:17-trixie@sha256:ff87138949ad9a46f32ff23bd6852b52ce024a13dffe9b4087be98292cdd3b4f` (PostgreSQL 17.11). These bytes passed the production/application, worker, and persistence checks in [the first stability run](https://github.com/xormania/xorgate/actions/runs/42414005346); that run failed only the new source guard because the baseline omitted an inbox icon and did not ignore Symfony's generated IDE helper. The icon is now committed from [the existing pinned UXor revision](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/assets/icons/flowbite/inbox-outline.svg). Future database digest updates are reviewable dependency PRs. Each run records the running database container's actual image digest.

This is not a byte-for-byte build guarantee. Apt repositories, extension/build downloads, external availability, and the evolving Ubuntu runner image remain inputs. Retained runtime facts help distinguish those changes from application regressions.

## Evidence and failure diagnosis

Artifacts include the run ID and attempt number and are retained for 14 days:

- `foundation-inputs-<run>-<attempt>`: installed dependency/recipe inputs, including lockfiles.
- `foundation-evidence-<run>-<attempt>`: source revision/status, PHPUnit and smoke reports, Compose status/logs, image IDs, and observed PHP/Composer/PostgreSQL/Docker versions.

The final collector also copies `/app/var/ci` from the application container into a separate `container/` subdirectory. That path lives in a named volume and is not the checkout's `var/ci`; without the copy, Toolkit installation logs and comparison outputs do not reach the upload. Missing containers are recorded in collector output without preventing other evidence from being saved. Diagnostic collection does not mask a failing application check.

The lease-recovery test uses the existing correction from [PR #11](https://github.com/xormania/xorgate/pull/11): rewind by 330 seconds against a 300-second lease, avoiding whole-second rounding at the boundary, and retain bounded worker/lock diagnostics. The fresh worker must still recover and ACK the original message. Whole-job retries are not used as a substitute for that assertion.

PR #11 adds Playwright intake checks separately. Preserve its submit/verify and restart-persistence checks when combining the branches.

## Maintenance boundaries

Composer's `lockfile-only` strategy updates resolved dependencies without widening `composer.json`. Exact AI Mate/Toolkit pins and Symfony's selected release line therefore stay deliberate. Security update availability also depends on the repository's GitHub security settings; this file does not establish that those settings are enabled or that every advisory is automatically repairable.

The native configuration deliberately leaves these reviewed inputs to an explicit update:

- xorder CLI and Serena image digests. They lack a channel tag; Dependabot resolves tagless digest updates from `latest`, which would not establish the intended PHP/tool compatibility. The CLI digest also appears in both Compose and the workflow and must be kept aligned.
- Composer executable version in the Dockerfile's extension-installer command.
- The UXor source revision and installed application-owned recipes.
- Importmap versions, the Tailwind binary pin, and the Dockerfile frontend directive.

For an update, change the relevant pin and any coupled references in one PR, run the same Foundation acceptance checks, inspect retained evidence, and merge after owner review. A source pin alone is not evidence that a new recipe installation or tool interface is compatible.

## References

- [GitHub: supported Dependabot ecosystems](https://docs.github.com/en/code-security/reference/supply-chain-security/supported-ecosystems-and-repositories).
- [GitHub: Dependabot options, grouping, update limits, and lockfile-only strategy](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference).
- [Dependabot: Docker tag prefix/suffix matching](https://github.com/dependabot/dependabot-core/blob/main/docker/README.md#supported-tag-schemas).
- [Dependabot: digest-only update behavior](https://github.com/dependabot/dependabot-core/blob/main/docker/lib/dependabot/docker/update_checker.rb).
- [GitHub: required status checks and strict versus loose rules](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets#require-status-checks-to-pass-before-merging).
- [GitHub: ruleset update API and administration permissions](https://docs.github.com/en/rest/repos/rules#update-a-repository-ruleset).
- [PHP extension installer: explicit Composer versions](https://github.com/mlocati/docker-php-extension-installer#other-utilities).
