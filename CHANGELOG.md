# v1.4.1
## 01/08/2022

1. [](#bugfix)
   * Fixed wrong order of parameters in `implode`, causing redirect to throw exception.
   * Fixed issue with certain file types not being deletable.

# v1.4.0
## 01/08/2022

1. [](#new)
   * It is now possible to delete items [#41](https://github.com/getgrav/grav-plugin-data-manager/pull/41), [#42](https://github.com/getgrav/grav-plugin-data-manager/pull/42)
   * Allow CVS even with list customization [#35](https://github.com/getgrav/grav-plugin-data-manager/pull/35)
   * Mark visited data items to different color [#24](https://github.com/getgrav/grav-plugin-data-manager/pull/24)
2. [](#bugfix)
   * Properly store generated CSV files into tmp/cache folder before download, rather than Grav root [#27](https://github.com/getgrav/grav-plugin-data-manager/pull/27)

# v1.3.0
## 10/08/2021

1. [](#improved)
   1. Support for multi-site and `user-data://` data stream instead of hardcoded `DATA_DIR` [#40](https://github.com/getgrav/grav-plugin-data-manager/pull/40)

# v1.2.0
## 04/13/2018

1. [](#new)
    * Added support for JSON and HTML files
    * Added support for the new form raw data format (Forms v2.13.3)
1. [](#improved)
    * Improved data format detection
    * Improved layout when viewing individual data item
1. [](#bugfix)
    * Fixed crash if loading data fails because of bad input (display raw text instead)
    * Fixed CSV output if the fields of the data has been changed over time

# v1.1.1.0
## 04/09/2018

1. [](#new)
    * Added basic CSV export of data
1. [](#improved)
    * Sort files by filename rather than the order they are found in filesystem
    * Added german translation

# v1.0.7
## 10/24/2016

1. [](#improved)
    * Added Romanian translation
1. [](#bugfix)
    * Avoid error if a file is found in the `user/data` folder (fixes `licences.yaml` issue), ignore the file instead

# v1.0.6
## 07/14/2016

1. [](#improved)
    * Added danish language

# v1.0.5
## 04/26/2016

1. [](#bugfix)
    * Default to use `.yaml` data files extension. Also check for `.txt`

# v1.0.4
## 02/18/2016

1. [](#bugfix)
    * Fix the enabled field type, make it visible
1. [](#improved)
    * Added admin translations
    * Dropped custom twig extension, uses Grav core one
    * Use onAdminMenu instead of the deprecated onAdminTemplateNavPluginHook

# v1.0.3
## 10/21/2015

1. [](#bugfix)
    * Only run in admin

# v1.0.2
## 10/07/2015

1. [](#bugfix)
    * Fixed incorrect icon

# v1.0.1
## 09/16/2015

1. [](#new)
    * New `onDataTypeExcludeFromDataManagerPluginHook()` plugin hook
1. [](#bugfix)
    * Single item fields visualization: strip all tags except `br` to allow multi-line

# v1.0.0
## 09/11/2015

1. [](#new)
    * ChangeLog started...
