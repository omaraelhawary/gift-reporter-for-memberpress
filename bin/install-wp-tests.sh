#!/usr/bin/env bash

if [ $# -lt 3 ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
    if [ $(which curl) ]; then
        curl -s "$1"
    elif [ $(which wget) ]; then
        wget -q -O - "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
    WP_TESTS_TAG="tags/$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    WP_TESTS_TAG="trunk"
fi

set -ex

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi

    mkdir -p "$WP_CORE_DIR"

    if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
        mkdir -p /tmp/wordpress-trunk
        rm -rf /tmp/wordpress-trunk/*
        download https://codeload.github.com/WordPress/WordPress/zip/trunk | tar -xz --strip-components=1 -C /tmp/wordpress-trunk
        mv /tmp/wordpress-trunk/* "$WP_CORE_DIR/"
    elif [[ $WP_VERSION == 'latest' ]]; then
        local ARCHIVE_NAME='latest'
        download https://wordpress.org/${ARCHIVE_NAME}.tar.gz | tar --strip-components=1 -xz -C "$WP_CORE_DIR"
    else
        download https://wordpress.org/wordpress-$WP_VERSION.tar.gz | tar --strip-components=1 -xz -C "$WP_CORE_DIR"
    fi
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR/includes" ]; then
        return
    fi

    mkdir -p "$WP_TESTS_DIR"

    svn export --quiet https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
    svn export --quiet https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ "$WP_TESTS_DIR/data"
}

configure_test_suite() {
    cd "$WP_TESTS_DIR"

    if [ ! -f wp-tests-config.php ]; then
        download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php > wp-tests-config.php
        sed -i.bak "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" wp-tests-config.php
        sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" wp-tests-config.php
        sed -i.bak "s/yourusernamehere/$DB_USER/" wp-tests-config.php
        sed -i.bak "s/yourpasswordhere/$DB_PASS/" wp-tests-config.php
        sed -i.bak "s|localhost|${DB_HOST}|" wp-tests-config.php
        rm -f wp-tests-config.php.bak
    fi
}

recreate_db() {
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
    mysqladmin drop "$DB_NAME" -f --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST"
}

install_wp
install_test_suite
configure_test_suite

if [ "$SKIP_DB_CREATE" != "true" ]; then
    recreate_db
fi

echo "WordPress test suite installed."
echo "WP_CORE_DIR=$WP_CORE_DIR"
echo "WP_TESTS_DIR=$WP_TESTS_DIR"
