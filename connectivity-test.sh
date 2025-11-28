#!/bin/sh
# Connectivity Test Script for pfSense

HOST="8.8.8.8"
REPORT="/usr/local/pkg/connectivity-test-report.json"

LATENCY=$(ping -c 4 -q $HOST 2>/dev/null | awk -F'/' 'END{ print $(NF-1) }')

if command -v /usr/local/bin/speedtest-cli >/dev/null 2>&1; then
  RESULT=$(/usr/local/bin/speedtest-cli --simple 2>/dev/null)
  DOWNLOAD=$(echo "$RESULT" | awk '/Download:/ {print $2 " " $3}')
  UPLOAD=$(echo "$RESULT" | awk '/Upload:/ {print $2 " " $3}')
elif command -v /usr/local/bin/speedtest-go >/dev/null 2>&1; then
  RESULT=$(/usr/local/bin/speedtest-go --unix 2>/dev/null)
  DOWNLOAD=$(echo "$RESULT" | awk '/Download:/ {print $2 " " $3}')
  UPLOAD=$(echo "$RESULT" | awk '/Upload:/ {print $2 " " $3}')
else
  DOWNLOAD="N/A"
  UPLOAD="N/A"
fi

NOW=$(date +"%Y-%m-%d %H:%M:%S")

echo "{\"timestamp\":\"$NOW\",\"latency_ms\":\"$LATENCY\",\"download\":\"$DOWNLOAD\",\"upload\":\"$UPLOAD\"}" >> "$REPORT"