#!/bin/sh
# Script to generate sample connectivity test results for testing the widget
# Results will be saved to /usr/local/pkg/connectivity-test-report.json
# Some sample data will be generated in this format:
# {"timestamp":"2025-11-28 23:22:55","latency_ms":"15.357","download":"74.85 Mbps","upload":"18.75 Mbps","packet_loss":"0.35%"}
# {"timestamp":"2025-11-28 23:26:28","latency_ms":"15.710","download":"75.00 Mbps","upload":"18.79 Mbps","packet_loss":"0.00%"}
# {"timestamp":"2025-11-28 23:30:28","latency_ms":"15.709","download":"72.73 Mbps","upload":"18.33 Mbps","packet_loss":"1.06%"}

# This will generate a series of N sample results as specified in argument
# Range timestamp is random within last 24 hours
# Usage: ./generate-results.sh [N]

# Timestamp format: YYYY-MM-DD HH:MM:SS
# Latency in ms, range 10-100 ms
# Download speed in Mbps, range 50-150 Mbps
# Upload speed in Mbps, range 10-50 Mbps
# Packet loss in %, range 0-5%

N=${1:-10}
REPORT="/usr/local/pkg/connectivity-test-report.json"
: > "$REPORT"

i=1
while [ "$i" -le "$N" ]; do
    # Portable timestamp: subtract i*5 minutes from now
    # GNU date: date -d "now - $((i * 5)) minutes"
    # BSD date (macOS, *BSD): date -v-"$((i * 5))"M
    # We'll try GNU date, fallback to BSD date
    MINUTES=$((i * 5))
    if date -d "now - $MINUTES minutes" +"%Y-%m-%d %H:%M:%S" >/dev/null 2>&1; then
        TIMESTAMP=$(date -d "now - $MINUTES minutes" +"%Y-%m-%d %H:%M:%S")
    else
        TIMESTAMP=$(date -v-"${MINUTES}"M +"%Y-%m-%d %H:%M:%S")
    fi

    # Use a unique seed for each value to improve randomness
    SEED=$(( $(date +%s) + $$ + i ))

    LATENCY=$(awk -v min=10 -v max=100 -v s=$SEED 'BEGIN{srand(s); printf "%.3f", min+rand()*(max-min)}')
    DOWNLOAD=$(awk -v min=50 -v max=150 -v s=$((SEED+1)) 'BEGIN{srand(s); printf "%.2f", min+rand()*(max-min)}')
    UPLOAD=$(awk -v min=10 -v max=50 -v s=$((SEED+2)) 'BEGIN{srand(s); printf "%.2f", min+rand()*(max-min)}')
    PACKET_LOSS=$(awk -v min=0 -v max=5 -v s=$((SEED+3)) 'BEGIN{srand(s); printf "%.2f", min+rand()*(max-min)}')

    echo "{\"timestamp\":\"$TIMESTAMP\",\"latency_ms\":\"$LATENCY\",\"download\":\"$DOWNLOAD Mbps\",\"upload\":\"$UPLOAD Mbps\",\"packet_loss\":\"$PACKET_LOSS%\"}" >> "$REPORT"
    i=$((i + 1))
done

echo "Generated $N sample results in $REPORT"