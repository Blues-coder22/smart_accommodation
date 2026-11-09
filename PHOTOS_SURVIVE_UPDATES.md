# Photos that survive a new version of the website (v9.17)

## What was going wrong
Listing and attraction photos are real files in `uploads/accommodations` and `uploads/attractions`. The database only stores
their file NAMES. Each new version of the project comes with an empty `uploads/` folder, so after replacing the folder every
name in the database pointed at a file that no longer existed. Adding the photos again then created duplicates.

## What happens now (automatic, nothing to switch on)
`includes/media_store.php` runs from `includes/db.php`, at most once a minute and immediately on the first page opened after a version change:

1. **Backup:** every photo the database refers to is copied into the database (tables `media_files` and `media_chunks`, created automatically).
2. **Restore:** a photo that is missing from `uploads/` is written back from the database. Pages did not change; the same `../uploads/...` addresses work again.
3. **No duplicates:** identical photos (same SHA-1) inside one listing or one attraction are reduced to one. Adding the same photo again no longer shows it twice.
4. **Deleted stays deleted:** a photo you remove is never restored. Its backup is dropped after a day.

Profile photos already lived in the database and are unchanged.

## Installing a new version
1. Keep your database (do not drop `smart_accommodation` and do not reset MySQL's data folder).
2. Replace the project folder, or extract the zip over it. Both are fine now.
3. Open any page. The photos reappear within a second or two (the first page after a big restore can take a few seconds).
4. Check **Admin > Photo safekeeping** (`admin/photos.php`): "Missing, can be restored" should drop to 0.

## Photos that were already lost before this version
No copy exists in the database, so they cannot be restored from it. If you still have an OLD project folder, copy its
`uploads/accommodations` and `uploads/attractions` files into the new `uploads` folders, then press **Check and repair now**
on the Photo safekeeping page. They are saved in the database and will never need copying again. If the old folder is gone, upload those photos once
more (or press **Remove broken records** to clear the empty entries first).

## Not covered on purpose
- `private_uploads/id_documents` (host and tourist ID photos) are not copied into the database, for privacy. Keep that folder when you update
  (copy it across), or back it up separately.
- `uploads/evidence` (incident report photos) are not copied either.

## Size
Photos take space in the database (shown on the Photo safekeeping page). Back up the database as well as the folder (see the backup advice).
Files over 8 MB are skipped; the upload forms already limit photos to 5 MB.
