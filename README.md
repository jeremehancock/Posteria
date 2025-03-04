<h1><img src="https://raw.githubusercontent.com/jeremehancock/Posteria/main/images/logo.png" height="50" /> Posteria</h1>

Posteria is a web-based media poster management system that allows you to organize and store custom posters for your movies, TV shows, seasons, and collections. It provides an elegant interface for uploading, importing, managing, and accessing your media artwork.

## Features
Here's the updated version with the PWA line added:

- 🖥️ Clean, modern interface for managing media posters
- 📁 Organized categories for Movies, TV Shows, TV Seasons, and Collections
- 🔍 Fast, fuzzy search functionality
- 📱 Mobile-responsive design
- 📲 Installable as a PWA (Progressive Web App)
- 🔒 Simple authentication system
- ⚡ Easy poster upload from local files or URLs
- 📥 Import posters from Plex
- 📤 Export posters to Plex
- 🧹 Orphan Poster detection
- 🎨 Support for JPG, JPEG, PNG, and WebP formats

## Support this project

[![Donate](https://raw.githubusercontent.com/jeremehancock/Posteria/main/images/donate.png)](https://www.buymeacoffee.com/jereme)


## Screenshot
![Posteria](https://raw.githubusercontent.com/jeremehancock/Posteria/main/images/screenshot.png "Posteria")

## Where to Find Posters

Looking for high-quality posters for your media library? Here are some excellent resources:

- **[The Poster Database](https://theposterdb.com/)** - A community-driven collection of custom posters with various styles and themes
- **[The Movie Database (TMDB)](https://www.themoviedb.org/)** - Extensive library of official and fan-made artwork
- **[The TV Database (TVDB)](https://www.thetvdb.com/)** - Comprehensive database for TV show posters and fanart
- **[Mediux](https://mediux.pro/)** - Collection of professionally designed media artwork

**Tip:** Posteria supports using Mediux YAML files in the URL uploader when changing posters, making it a convenient choice for managing your collection.

## Installation

1. Create a `docker-compose.yml` file with the following content:

```yaml
services:
  posteria:
    image: bozodev/posteria:latest
    container_name: posteria
    ports:
      - "1818:80"
    environment:
      - SITE_TITLE=Posteria
      - AUTH_USERNAME=admin                       # Change this!
      - AUTH_PASSWORD=changeme                 	  # Change this!
      - SESSION_DURATION=3600                     # In seconds
      - IMAGES_PER_PAGE=24
      - MAX_FILE_SIZE=5242880                     # In bytes
      - PLEX_SERVER_URL=   	  		  # Optional for Importing from Plex
      - PLEX_TOKEN=              	          # Optional for Importing from Plex
    volumes:
      - ./posters/movies:/var/www/html/posters/movies
      - ./posters/tv-shows:/var/www/html/posters/tv-shows
      - ./posters/tv-seasons:/var/www/html/posters/tv-seasons
      - ./posters/collections:/var/www/html/posters/collections
    restart: unless-stopped
```

2. Start the container:
```bash
docker-compose up -d
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| SITE_TITLE | Website title | Posteria |
| AUTH_USERNAME | Admin username | admin |
| AUTH_PASSWORD | Admin password | changeme |
| SESSION_DURATION | Login session duration in seconds | 3600 (1 Hour) |
| IMAGES_PER_PAGE | Number of posters displayed per page | 24 |
| MAX_FILE_SIZE | Maximum upload file size in bytes | 5242880 (5MB) |
| PLEX_SERVER_URL | URL for your Plex Server | ex: http://your-server:32400
| PLEX_TOKEN | Plex Server Token | [More info](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/) |
### Volume Mounts

The Docker container uses the following volume mounts:

- `./posters/movies`: Movie posters
- `./posters/tv-shows`: TV show posters
- `./posters/tv-seasons`: TV season posters
- `./posters/collections`: Collection posters

## Usage

1. Access the web interface at `http://your-server:1818`
2. Log in using your configured credentials
3. Import posters form Plex
4. Change posters:
	- Support for local file upload
	- Support for direct URL upload
5. Posters are automatically updated on Plex
6. Bulk export options also available
7. As you add more media to Plex just re-import to add new posters
8. Orphaned poster detection

## Security Considerations

1. Change the default username and password
2. Use HTTPS if exposing to the internet
3. Regularly backup your poster directories

## License

[MIT License](LICENSE)

## AI Assistance Disclosure

This tool was developed with assistance from AI language models.