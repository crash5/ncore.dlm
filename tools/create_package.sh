#!/usr/bin/env bash
set -e

readonly RELEASE_VERSION=${1:-"0.0.0"}
readonly OUTPUT_PATH=${2:-"output/"}

readonly RELEASE_FILE_NAME=ncore_v${RELEASE_VERSION}.dlm
readonly TEMPORARY_WORK_DIR=$(mktemp -d -p .)

export RELEASE_VERSION
tools/mo src/INFO.mustache > "${TEMPORARY_WORK_DIR}"/INFO
cp src/search.php "${TEMPORARY_WORK_DIR}"/

mkdir -p "${OUTPUT_PATH}"
tar -czf "${OUTPUT_PATH}"/${RELEASE_FILE_NAME} --mode a=r,a+X -C "${TEMPORARY_WORK_DIR}" ./

rm -rf "${TEMPORARY_WORK_DIR}"
