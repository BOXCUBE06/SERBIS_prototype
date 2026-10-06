# design/

`artboards/` holds copies of the calm-redesign artboards (`.dc.html`) from the
SERBIS design canvas: the `C_*` screens the mobile app is being restyled to
match, one file per screen.

Kept on `update-admin-vue` only, like `docs/` and `audits/`: it never goes to
`main`. When merging into `main`, stage with `--no-commit --no-ff`, then run

    git ls-files docs audits design

It must print nothing. If it lists files, `git rm -r --cached` those folders
before committing.
