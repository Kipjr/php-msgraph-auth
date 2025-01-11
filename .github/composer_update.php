name: Composer Update & Push
on:
  workflow_dispatch:

jobs:
  composer-update-push:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Install dependencies
        run: composer install --no-ansi --no-interaction --no-scripts --prefer-dist
      - name: Update dependencies
        run: composer update --no-ansi --no-interaction --no-scripts --prefer-dist
      - name: Bump version of dependencies to installed
        run: composer update --no-ansi --no-interaction --no-scripts --prefer-dist          
      - name: Push to repo
        run: |
          git config --local user.email "action@github.com"
          git config --local user.name "GitHub Action"
          git add composer.json composer.lock
          git diff-index --quiet HEAD || git commit -m "Add ./Vendor changes" && git push
