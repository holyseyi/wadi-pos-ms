#!/bin/bash

# POS Application Launcher
# This script starts the PHP development server and opens the application in the default browser

# Change to the application directory
cd "$(dirname "$0")"

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "PHP is not installed. Please install PHP 8.3 or later."
    exit 1
fi

# Find an available port starting from 8080
PORT=8080
while lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; do
    PORT=$((PORT + 1))
done

echo "Starting POS application on port $PORT..."

# Start PHP server in background
php -S localhost:$PORT > /dev/null 2>&1 &
SERVER_PID=$!

# Wait a moment for server to start
sleep 2

# Open browser
if command -v xdg-open &> /dev/null; then
    xdg-open "http://localhost:$PORT"
elif command -v firefox &> /dev/null; then
    firefox "http://localhost:$PORT"
elif command -v google-chrome &> /dev/null; then
    google-chrome "http://localhost:$PORT"
else
    echo "Please open your browser and navigate to: http://localhost:$PORT"
fi

echo "POS application is running at http://localhost:$PORT"
echo "Press Ctrl+C to stop the server"

# Wait for user to stop
trap "kill $SERVER_PID 2>/dev/null; exit" INT TERM
wait $SERVER_PID
