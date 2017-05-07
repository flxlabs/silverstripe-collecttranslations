# Collect Translations Task

Collects SilverStripe translations from template and code files.

## Installation
```
composer require flxlabs/silverstripe-collecttranslations
```

Or copy the CollectTranslatonsTask.php file to your `mysite/code` folder.


## Usage:

Visit `/tasks` to view detailed information about the task.

Call `/tasks/CollectTranslationsTask` to collect all the translations in your template and code files.

Call `/tasks/CollectTranslationsTask?compare=en` to compare the translations to an existing language file,
replacing "en" with the language you wish to compare to. The task will merge the existing file with all the 
found translations, and display the result. New entries will be marked with `[*]`.
