# Changelog

## [1.0.8]

### Added

- Added version info to UI

## [1.0.9]

### Added

- Enhanced Upload from URL image preview with fullscreen view

### Changed

- Updated Version Upgrade modal with link to this Changelog

## [1.1.0]

### Fixed

- Upload from URL input mobile issue

## [1.1.1]

### Added

- Added poster preview for Upload from Disk option

## [1.1.2]

### Added

- Added new environment variable to ensure compatibility with Kometa

## [1.1.3]

### Added

- Added Support Development modal

## [1.1.4]

### Added

- Added missing Cancel buttons in some modals

## [1.1.5]

### Fixed

- CSS Tweaks for the filter buttons and modal close icons
- Updated the Change Poster to pull the Max File Size from config

## [1.1.6]

### Added

- Fancy Tooltips

## [1.1.7]

### Added

- Multi Library Select on Import
- Added the Library Name to the overlay badge when there are more than one library of the same media type.
- Added Reset option to start fresh
- The filenames now include the Library Names

## [1.1.8]

### Fixed

- Fixed issue with Send to Plex and Export not removing Overlay labels if that option is set to true.

## [1.1.9]

### Fixed

- Gallery Stats not showing how many images are showing when filtered

### Added

- Added config option to ignore articles in sort

## [1.2.0]

### Added

- Scheduled import config option

## [1.2.1]

### Fixed

- Poster names in Change Poster modal issue

## [1.2.2]

### Added

- Mobile tooltip to show truncated Poster names

## [1.2.3]

### Fixed

- Fixed cursor style inconsistency

## [1.2.4]

### Added

- Added TMDB support for TV Seasons

## [1.2.5]

### Added

- Added TMDB support for Collections

### Fixed

- Updated TMDB search to return all posters that match the media title

## [1.2.6]

### Fixed

- Search not handling special characters
- Updated import to handle special characters. This will cause duplicate posters for previous users. The "Reset" option is recommended and then a full import to ensure the duplicates.

## [1.2.7]

### Fixed

- Cleaned up code formatting

## [1.2.8]

### Fixed

- Removed unused code

## [1.2.9]

### Fixed

- Fixed edge case issue where multi library import followed by single would create orphans incorrectly
- Fixed edge case issue of multi library import for Collections creating duplicates when followed by single library import

## [1.3.0]

### Fixed

- Fixed issue of not being able to search for orphaned files

## [1.3.1]

### Fixed

- Fixed bug in auto import causing it to fail

## [1.3.2]

### Fixed

- Removed season badges from TMDB results for TV Seasons since they weren't always accurate

## [1.3.3]

### Added

- Updated the filter buttons to maintain the current search

## [1.3.4]

### Added

- Added support for pulling posters from Fanart.tv as well as TMDB
- Added attribution link for both Fanart.tv and TMDB

## [1.3.5]

### Fixed

- Fixed duplicate error preview when no poster is found during TMDB/Fanart.tv search

## [1.3.6]

### Fixed

- Get from Plex option adding Library Name to filename

## [1.3.7]

### Fixed

- Various issues with special characters in the Poster name and/or the Library Name

## [1.3.8]

### Fixed

- Get from Plex issues

### Added

- Added year to movie titles

## [1.3.9]

### Fixed

- Fixed issue with filenames in Windows SMB Shares.

  - This will be a breaking change and require everyone to use the Reset option to clear out all previously imported files.

## [1.4.0]

### Added

- Option to sort by recently added in Plex

### Removed

- Removed the Export all option since it is not really needed and is not very stable

## [1.4.1]

### Fixed

- Search bug
- Mobile caption scroll bug and updates styling
- Removed uneeded poster grid overlay
- Adjusted header margins

## [1.4.2]

### Added

- TVDB support for posters in the Find Posters option

### Fixed

- Issues with Star Wars in the Find Posters options

## [1.4.3]

### Removed

- Find Posters modal filter since it really didn't have much use

## [1.4.4]

### Added

- Added new Poster Wall feature

## [1.4.5]

### Fixed

- Poster wall showing wrong poster for TV Shows

## [1.4.6]

### Fixed

- Poster Wall would only show 20 random posters

## [1.4.7]

### Fixed

- Enhanced randomization of poster wall

## [1.4.8]

### Added

- Explicit poster locking to help prevent Plex from changing posters that have been updated via Posteria
- Added Toggle Sort by Date Added button

### Fixed

- Mobile layout issues
- Flickering of buttons on URL changes
- Updated lazy loading so changes in Posteria refresh the cache

## [1.4.9]

### Fixed

- Fixed persmissions issues in the Auto Import

## [1.5.0]

### Added

- Full Screen button for posters

## [1.5.1]

### Added

- Full Screen view for the Find Posters option so you can preview them before selecting final poster

## [1.5.2]

### Fixed

- Issue with buttons in overlay of Find Posters modal

## [1.5.3]

### Fixed

- Issues with randomization in Poster Wall and cleaned up transitions

## [1.5.4]

### Added

- Option to bypass authentication

### Fixed

- Poster Wall showing Live TV active streams on initial load

## [1.5.5]

### Fixed

- Poster Wall background transitions

## [1.5.6]

### Fixed

- Issue with Lazy Loading and posters with # in the filename

## [1.5.7]

### Fixed

- Issue with Poster Wall getting stuck

## [1.5.8]

### Fixed

- Issue with Poster Wall not pulling from all available posters

## [1.5.9]

### Fixed

- Search was too fuzzy. Now it is more specific

## [1.6.0]

### Fixed

- Fixed poster fetch :)

## [1.6.1]

### Updated

- Updated to have smaller docker container Thanks @gaufre2
- See: https://github.com/jeremehancock/Posteria/pull/54

## [1.6.2]

### Fixed

- Fixed issues in Firefox

## [1.6.3]

### Fixed

- Fixed issues with import

## [1.6.4]

### Fixed

- Fixed permission issues

## [1.6.5]

### Updated

- Made some tweaks to the Poster Wall logic

## [1.6.6]

### Fixed

- Fixed container failing at restart Thanks @gaufre2

## [1.6.7]

### Added

- Added Filter View option to the Delete Orphans modal

## [1.6.8]

### Fixed

- Server time sync issue with backend API

## [1.6.9]

### Fixed

- Fixed issue with poster-wall link

## [1.7.0]

### Updated

- Updated to work for arm devices

## [1.7.1]

### Updated

- Updated to hide the pull to refresh progress bar

## [1.7.2]

### Updated

- Updated to allow for exluding Libraries
- Added the ability to refresh since the issue in Android seems to be resolved
