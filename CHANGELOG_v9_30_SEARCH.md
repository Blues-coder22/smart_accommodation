# v9.30 - Search fixes and fairer rating score

## What changed
1. **Stay filters now apply on every search path.** New helper `accommodationFilterSql()` (includes/functions.php) holds the
   price, property type, guests, dates (no overlapping booking, no host-blocked night) and amenity rules.
   It is used by the map search (`getNearbyAccommodations`), the typed-text search and the general search.
   Before: the typed-text path ignored all filters; the general path ignored guests, dates and amenities.
2. **Attraction search** (includes/attraction_search.php, tourist/search.php):
   * matches name, place, category and description;
   * spelling variants through a small alias list (e.g. Masai Mara / Maasai Mara / Mara, Mt Kenya / Mount Kenya);
   * default order: best text match, then rating, then A to Z (previously A to Z only);
   * the "Highest rated" and "near this stay" (distance) orders are unchanged.
3. **Confidence-adjusted rating** in `applyWeightedScoring`: rating part = (n x average + 5 x 3.0) / (n + 5), n = published reviews.
   One 5-star review (score 3.33) no longer beats 100 reviews averaging 4.6 (4.52). No reviews = 3.0, as before.
   The home page "featured" query uses the same formula for its top 9. Weights are unchanged (40/30/20/10).
   "Sort: Highest rated" still sorts by the plain average, with the number of reviews as tie-breaker.

## Files
* new: includes/attraction_search.php, CHANGELOG_v9_30_SEARCH.md
* changed: includes/functions.php, tourist/search.php, index.php
* no database changes

## Known limits
* Not run: no PHP or MySQL was available when this was written. Checked by reading the code and bracket balance only.
  Run the tests below on XAMPP before relying on it.
* No typo tolerance beyond the alias list. Stored names containing an apostrophe are saved HTML-encoded, so an alias such as
  "hells gate" matches by its other words.
* The alias list is short; add the places in your data to `attractionAliasGroups()`.

## Quick tests
1. Search a town with max price 5,000: no result above KES 5,000 (typed-text path, before the map loads).
2. Search with dates that overlap a confirmed booking: that stay is not listed (typed-text and general paths).
3. Guests = 6: stays with max_guests below 6 are not listed on any path.
4. Attractions: "Masai Mara" and "Maasai Mara" return the same place first; a word that only appears in a description finds it, ranked after name matches.
5. Stay A: one 5-star review. Stay B: 20 reviews averaging 4.6, otherwise equal. B ranks above A on Recommended.
