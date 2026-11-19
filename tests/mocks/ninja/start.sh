#!/bin/sh
# Run by tests/mocks/install.php after copying the stand-in: set NinjaOne up on the test install against it (setup.php).
php "$(dirname "$0")/setup.php" "$1"
