#!/usr/bin/env sh
# Install script for pfSense Connectivity Test Widget

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

show_usage() {
    printf "%b\n" "${YELLOW}Usage: $0 --install | --uninstall${NC}"
    exit 1
}

if [ $# -ne 1 ]; then
    show_usage
fi

case "$1" in
    --install)
        printf "%b\n" "${CYAN}Checking for speedtest installation...${NC}"
        # Install speedtest from pkg if not present
        if ! command -v speedtest >/dev/null 2>&1; then

            printf "%b\n" "${YELLOW}Speedtest not found. Installing package...${NC}"
            # Ask user to select which speedtest package to install from pkg search results
            # Search for speedtest packages and build a list to use for selection
            printf "%b\n" "${CYAN}Searching for available speedtest packages...${NC}"
            SPEEDTEST_PKGS=$(pkg search -qo speedtest | awk '{print $1}')
            if [ -z "$SPEEDTEST_PKGS" ]; then
                printf "%b\n" "${RED}No speedtest packages found in pkg repository.${NC}"
                exit 1
            fi
            printf "%b\n" "${CYAN}Available speedtest packages:${NC}"
            i=1
            for PKG in $SPEEDTEST_PKGS; do
                printf "%b\n" "  $i) $PKG"
                eval "PKG_$i=$PKG"
                i=$((i+1))
            done
            printf "%b" "${YELLOW}Select package to install [1-$(($i-1))]: ${NC}"
            read CHOICE
            eval "SELECTED_PKG=\$PKG_$CHOICE"
            if [ -n "$SELECTED_PKG" ]; then
                printf "%b\n" "${CYAN}Installing $SELECTED_PKG...${NC}"
                pkg install -y "$SELECTED_PKG"
            else
                printf "%b\n" "${RED}Invalid selection.${NC}"
                exit 1
            fi
        else
            printf "%b\n" "${GREEN}Speedtest is already installed.${NC}"
        fi

        # Check wich speedtest package is installed
        if command -v speedtest-cli >/dev/null 2>&1; then
            printf "%b\n" "${GREEN}speedtest-cli is installed and will be used for speed tests.${NC}"
        elif command -v speedtest-go >/dev/null 2>&1; then
            printf "%b\n" "${GREEN}speedtest-go is installed and will be used for speed tests..${NC}"
        else
            printf "%b\n" "${RED}No supported speedtest command found after installation.${NC}"
            exit 1
        fi

        # Copy the shell script
        printf "%b\n" "${CYAN}Installing connectivity test script...${NC}"
        install -m 0755 connectivity-test.sh /usr/local/bin/connectivity-test.sh

        # Copy the widget
        printf "%b\n" "${CYAN}Installing connectivity widget...${NC}"
        install -m 0644 connectivity_test.widget.php /usr/local/www/widgets/widgets/connectivity_test.widget.php

        # Ensure data file exists and is correct permissions
        printf "%b\n" "${CYAN}Setting up data file...${NC}"
        touch /usr/local/pkg/connectivity-test-report.json
        chmod 600 /usr/local/pkg/connectivity-test-report.json

        # Add cron job if not already present
        printf "%b\n" "${CYAN}Setting up cron job...${NC}"

        # Ask user for cron frequency in minutes, default to 30 minutes if no input or if user inputs 0 or negative number
        # Check if input is a valid positive integer and if is greater than 60 set proper hours and minutes
        while true; do
            printf "%b" "${YELLOW}Enter cron job frequency in minutes (default 30): ${NC}"
            read CRON_FREQ
            if [ -z "$CRON_FREQ" ]; then
                CRON_FREQ=30
                break
            elif ! echo "$CRON_FREQ" | grep -qE '^[0-9]+$'; then
                printf "%b\n" "${RED}Invalid input. Please enter a positive integer.${NC}"
            elif [ "$CRON_FREQ" -le 0 ]; then
                printf "%b\n" "${RED}Please enter a positive integer greater than 0.${NC}"
            else
                break
            fi
        done
        if [ "$CRON_FREQ" -lt 60 ]; then
            printf "%b\n" "${CYAN}Setting cron job to run every $CRON_FREQ minutes...${NC}"
            CRON_ENTRY="*/$CRON_FREQ * * * * root /usr/local/bin/connectivity-test.sh"
        else
            HOURS=$((CRON_FREQ / 60))
            MINUTES=$((CRON_FREQ % 60))
            printf "%b\n" "${CYAN}Setting cron job to run every $HOURS hours and $MINUTES minutes...${NC}"
            if [ "$MINUTES" -eq 0 ]; then
                CRON_ENTRY="0 */$HOURS * * * root /usr/local/bin/connectivity-test.sh"
            else
                CRON_ENTRY="$MINUTES */$HOURS * * * root /usr/local/bin/connectivity-test.sh"
            fi
        fi
        if [ -f /etc/cron.d/connectivity-test ]; then
            printf "%b\n" "${YELLOW}Cron job already exists. Overwriting...${NC}"
        fi
        echo "$CRON_ENTRY" > /etc/cron.d/connectivity-test

        printf "%b\n" "${GREEN}Connectivity Test and Widget installed.${NC}"
        printf "%b\n" "${CYAN}Add the 'Connectivity Reports' widget to your pfSense dashboard.${NC}"
        ;;
    --uninstall)
        printf "%b\n" "${CYAN}Uninstalling connectivity test script and widget...${NC}"
        rm -f /usr/local/bin/connectivity-test.sh
        rm -f /usr/local/www/widgets/widgets/connectivity_test.widget.php
        rm -f /usr/local/pkg/connectivity-test-report.json

        printf "%b\n" "${CYAN}Removing cron job...${NC}"
        if [ -f /etc/cron.d/connectivity-test ]; then
            rm -rf /etc/cron.d/connectivity-test
            printf "%b\n" "${GREEN}Cron job removed.${NC}"
        else
            printf "%b\n" "${YELLOW}Cron job does not exist. Skipping removal.${NC}"
        fi

        # Search for packages installed using pkg list and ask user if they want to remove them
        printf "%b\n" "${CYAN}Checking for installed speedtest packages...${NC}"
        INSTALLED_SPEEDTEST_PKGS=$(pkg info | grep -E 'speedtest|speedtest-go' | awk '{print $1}')
        if [ -n "$INSTALLED_SPEEDTEST_PKGS" ]; then
            printf "%b\n" "${CYAN}The following speedtest packages are installed:${NC}"
            printf "%b\n" "$INSTALLED_SPEEDTEST_PKGS"
            printf "%b" "${YELLOW}Do you want to remove them? [y/N]: ${NC}"
            read REMOVE_CHOICE
            if [ "$REMOVE_CHOICE" = "y" ] || [ "$REMOVE_CHOICE" = "Y" ]; then
                for PKG in $INSTALLED_SPEEDTEST_PKGS; do
                    printf "%b\n" "${CYAN}Removing package $PKG...${NC}"
                    pkg delete -y "$PKG"
                done
            else
                printf "%b\n" "${YELLOW}Skipping package removal.${NC}"
            fi
        else
            printf "%b\n" "${GREEN}No speedtest packages found to remove.${NC}"
        fi
        printf "%b\n" "${GREEN}Uninstallation complete.${NC}"
        ;;
    *)
        show_usage
        ;;
esac