#!/usr/bin/env bash

TAG=$1

if [ "$TAG" == "" ]
then
  echo "Tag is required"
  exit 1
fi

git checkout main && \
  git pull && \
  git status

cat src/Http/Headers.php | sed "s/\(SDK_VERSION = '\)[0-9.]*\('\)/\1"$TAG"\2/" > /tmp/Headers.php
mv /tmp/Headers.php src/Http/Headers.php

git commit -m "Tag $TAG" && \
  git push && \
  git tag $TAG && \
  git push --tags &&
  git checkout main
