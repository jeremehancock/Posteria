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
