# shellcheck shell=bash
# Sourced by the test runners. Sets FRESHRSS_VERSIONS (unless already set) to
# the minimum supported FreshRSS version declared in metadata.json plus the
# newest version the suites are pinned to, so the declared minimum is always
# tested.

FRESHRSS_LATEST_TESTED_VERSION="1.30.1"

if [[ -z "${FRESHRSS_VERSIONS-}" ]]; then
    _metadata="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/metadata.json"
    _min_version="$(sed -n 's/.*"freshrss_min_version": *"\([^"]*\)".*/\1/p' "${_metadata}")"
    if [[ -z "${_min_version}" ]]; then
        echo "ERROR: freshrss_min_version not found in ${_metadata}" >&2
        exit 1
    fi
    if [[ "${_min_version}" == "${FRESHRSS_LATEST_TESTED_VERSION}" ]]; then
        FRESHRSS_VERSIONS="${_min_version}"
    else
        FRESHRSS_VERSIONS="${_min_version} ${FRESHRSS_LATEST_TESTED_VERSION}"
    fi
    unset _metadata _min_version
fi
export FRESHRSS_VERSIONS
