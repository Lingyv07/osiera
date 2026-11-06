# Contributing to Osiera

Thanks for taking the time to contribute. This document describes how to report a
problem and how to send a change that can be merged without a round of cleanup.

## Reporting a bug

Open an issue using the **Bug report** template and include:

- WordPress version, PHP version, and whether the site is multisite;
- the exact steps that reproduce the problem;
- what you expected and what happened instead;
- the PHP error log excerpt if there is one.

Screenshots help, but strip anything that identifies a client or a server
(database names, absolute paths, admin URLs).

**Do not report security issues in public.** Email the address in the repository
profile instead, and allow a reasonable window for a fix before disclosing.

## Making a pull request

1. Fork the repository and create a branch: `fix/inquiry-form-validation`,
   `feature/custom-archive-sort` — one topic per branch.
2. Keep the change focused. A PR that fixes three unrelated things will be asked
   to split.
3. Follow the existing code style: WordPress PHP coding standards, tabs for
   indentation, Yoda conditions, and a docblock on every new function.
4. Escape everything on output (`esc_html()`, `esc_attr()`, `esc_url()`),
   sanitize on input, and check capability plus nonce on every admin action.
5. Every user-facing string uses the `osiera` text domain.
6. Test on PHP 8.0 and the current WordPress release with `WP_DEBUG` on. The PR
   description should state which versions you tested.
7. Do not include `backup/`, `node_modules/`, `vendor/`, or built zip files.

## Commit messages

```
type(scope): short imperative summary

Why this change is needed, wrapped at 72 characters.
```

`type` is one of `feat`, `fix`, `refactor`, `style`, `docs`, `chore`, `perf`,
`test`. Example:

```
fix(inquiry): reject submissions without a valid nonce

The REST callback checked capability but not the nonce, so a logged-out
request could insert rows directly.
```

## Release process (maintainer)

1. Update the version in `style.css`, `readme.txt` (`Stable tag`) and
   `CHANGELOG.md`.
2. Commit as `chore(release): 1.1.0`.
3. `git tag -a v1.1.0 -m "1.1.0" && git push origin v1.1.0` — the release
   workflow builds the installable zip and attaches it to the GitHub release.

## License of contributions

By submitting a pull request you agree that your contribution is licensed under
the GNU General Public License v3.0 or later, the same license as the project.
