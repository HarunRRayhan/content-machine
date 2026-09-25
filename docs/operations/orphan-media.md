# Orphan media maintenance

`php artisan cm:cleanup-orphan-media` reports old, unreferenced files. It does
not modify storage. `--delete` opts into quarantine and permanent deletion:

```sh
php artisan cm:cleanup-orphan-media
php artisan cm:cleanup-orphan-media --delete
```

The command supports only the local `scratchpad` disk and files directly under
a numeric workspace directory whose names match the app's generated ULID upload
format or Pexels-import format. Uploads from `ResolvesMediaAsset` use
`<workspace>/<ULID>.<extension>`; Pexels imports use
`<workspace>/source-pexels-<photo-id>-<ULID>.(jpg|png|webp)`. Post images and
LinkedIn document uploads use the same `ResolvesMediaAsset` path. The presentation
library uses `<workspace>/presentation-library/<asset-key>.svg`, outside the
scanned filename shape. Video decks live in `videos.deck_manifest` and refer to
code-backed presentation-library assets, not uploaded files.

`media_assets.disk` and `media_assets.path` identify the stored file.
`attachments.media_asset_id` links assets to polymorphic content, and
`transcriptions.media_asset_id` links voice transcriptions to audio. Any
`MediaAsset` row with the exact scratchpad disk/path pair protects its file,
regardless of attachment or transcription state. The command never removes
`MediaAsset`, `Attachment`, or `Transcription` rows. It excludes subdirectories,
symlinks, the `local`/`public` disks, and non-local storage drivers.

The minimum grace period is seven days. With `--delete`, an eligible file is
moved under `.cm-orphan-quarantine/<UTC timestamp>/` first. A later explicit
`--delete` run permanently removes it only after another seven days, with the
database reference and local-path checks repeated. If a `MediaAsset` reference
appears while the file is quarantined, the command restores the file when the
original path is free. Quarantine files are outside the scanner's managed
upload prefix and are considered only by this command.

Uploaders do not share a cross-process lock with cleanup. The two-stage
quarantine and repeated checks reduce the race window, but cannot provide a
transaction spanning a database and filesystem. Generated upload names include
ULIDs and are not reused by the app. The command is an operator-invoked safety
maintenance tool; it is not registered on the scheduler or deployment path.
