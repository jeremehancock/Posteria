#!/bin/bash

# Posteria: A Media Poster Collection App
# Save all your favorite custom media server posters in one convenient place
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in all
# copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.
#
# Script to remove "Overlay" label from Plex media
# 
# Usage: ./remove-overlay-label.sh <ratingKey> <plexServerUrl> <plexToken>

if [ $# -lt 3 ]; then
    echo "Usage: $0 <ratingKey> <plexServerUrl> <plexToken>"
    exit 1
fi

RATING_KEY=$1
PLEX_SERVER_URL=$2
PLEX_TOKEN=$3

# Get current metadata
METADATA=$(curl -s -H "Accept: application/xml" -H "X-Plex-Token: $PLEX_TOKEN" \
    "$PLEX_SERVER_URL/library/metadata/$RATING_KEY")

# Extract library section ID
LIBRARY_SECTION_ID=$(echo "$METADATA" | grep -o 'librarySectionID="[^"]*"' | head -1 | cut -d'"' -f2)

if [ -z "$LIBRARY_SECTION_ID" ]; then
    echo "Error: Could not determine library section ID"
    exit 1
fi

# Extract media type
MEDIA_TYPE=$(echo "$METADATA" | grep -o 'type="[^"]*"' | head -1 | cut -d'"' -f2)

# Convert to type ID
case "$MEDIA_TYPE" in
    "movie") TYPE_ID="1" ;;
    "show") TYPE_ID="2" ;;
    "season") TYPE_ID="3" ;;
    "episode") TYPE_ID="4" ;;
    "collection") TYPE_ID="18" ;;
    *) TYPE_ID="1" ;;  # Default to movie
esac

# Extract media title
TITLE=$(echo "$METADATA" | grep -o 'title="[^"]*"' | head -1 | cut -d'"' -f2)
echo "Found media item: $TITLE (ID: $RATING_KEY, Library: $LIBRARY_SECTION_ID)"

# Check if Overlay label exists
OVERLAY_LABEL=$(echo "$METADATA" | grep -o '<Label[^>]*tag="Overlay"')

if [ -z "$OVERLAY_LABEL" ]; then
    echo "No Overlay label found on this item."
    exit 0
fi

# Get other labels
OTHER_LABELS=$(echo "$METADATA" | grep -o '<Label[^>]*tag="[^"]*"' | grep -v 'tag="Overlay"' | sed 's/.*tag="\([^"]*\)".*/\1/')

# Build label parameters
LABEL_PARAMS=""
i=0
while read -r label; do
    [ -z "$label" ] && continue
    encoded_label=$(echo "$label" | sed 's/ /%20/g' | sed 's/&/%26/g')
    LABEL_PARAMS+="&label%5B$i%5D.tag.tag=$encoded_label"
    i=$((i+1))
done <<< "$OTHER_LABELS"

# First, unlock the label field
curl -s -X PUT -H "X-Plex-Token: $PLEX_TOKEN" \
    "$PLEX_SERVER_URL/library/metadata/$RATING_KEY?label.locked=0" > /dev/null

# Wait for unlock to take effect
sleep 1

# Construct the URL to remove Overlay label
REMOVE_URL="$PLEX_SERVER_URL/library/sections/$LIBRARY_SECTION_ID/all?type=$TYPE_ID&id=$RATING_KEY&includeExternalMedia=1&thumb.locked=1&collection.locked=1&label.locked=1&label%5B%5D.tag.tag-=Overlay$LABEL_PARAMS"

# Remove the Overlay label
RESULT=$(curl -s -v -X PUT -H "X-Plex-Token: $PLEX_TOKEN" "$REMOVE_URL" 2>&1)
HTTP_CODE=$(echo "$RESULT" | grep -m1 "HTTP/" | grep -o "[0-9][0-9][0-9]")

if [[ "$HTTP_CODE" =~ ^2[0-9][0-9]$ ]]; then
    # Refresh metadata
    curl -s -X PUT -H "X-Plex-Token: $PLEX_TOKEN" \
        "$PLEX_SERVER_URL/library/metadata/$RATING_KEY/refresh" > /dev/null
    
    # Wait for refresh to take effect
    sleep 1
    
    # Refresh library section
    curl -s -X GET -H "X-Plex-Token: $PLEX_TOKEN" \
        "$PLEX_SERVER_URL/library/sections/$LIBRARY_SECTION_ID/refresh" > /dev/null
    
    # Add a longer delay to ensure all changes take effect
    sleep 3
    
    # Verify the label removal
    VERIFY_METADATA=$(curl -s -H "Accept: application/xml" -H "X-Plex-Token: $PLEX_TOKEN" \
        "$PLEX_SERVER_URL/library/metadata/$RATING_KEY")
    
    OVERLAY_STILL_EXISTS=$(echo "$VERIFY_METADATA" | grep -o '<Label[^>]*tag="Overlay"')
    
    if [ -z "$OVERLAY_STILL_EXISTS" ]; then
        echo "SUCCESS: Overlay label has been successfully removed!"
        exit 0
    else
        echo "WARNING: Overlay label still appears to be present despite successful API calls."
        exit 1
    fi
else
    echo "ERROR: Label removal failed with HTTP $HTTP_CODE"
    exit 1
fi
