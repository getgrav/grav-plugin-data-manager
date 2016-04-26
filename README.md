# Grav Data Manager Plugin

The **Data Manager Plugin** for [Grav](http://github.com/getgrav/grav) adds the ability to visualize data. This is particularly useful for the **admin** and **form** plugins.
Additional plugins may store data content, and the Data plugin - properly configured - is able to show their data too.

| IMPORTANT!!! This plugin is currently in development as is to be considered a **beta release**.  As such, use this in a production environment **at your own risk!**. More features will be added in the future.

# Installation

The Data plugin is easy to install with GPM.

```
$ bin/gpm install data-manager
```

Or clone from GitHub and put in the `user/plugins/data-manager` folder.

# Configuration

You don't need any configuration to start using the plugin.
Knowing how to configure it, as illustrated later, will allow you to take advantage of a few handy customization options.

Remember: copy the `user/plugins/data-manager/data-manager.yaml` into `user/config/plugins/data-manager.yaml` and make your modifications in your user folder.

# Usage

Once installed the Data plugin shows a `Data Manager` item in the admin menu. Click that, and the Data plugin will show you the available data types found in the user/data folder.

Clicking a data type will show the list of items. For example you might have a contact form setup in your site, using the Form plugin. A `Contact` type should show up, depending on how you called that form data items (data types names are taken from the user/data/ folder names, unless customized - see below).

For example you might have a `form.md` contact form with:

```yaml
---
title: A page with a form
form:
    name: contactform
    fields:
        - name: name
          label: Name
          placeholder: Enter your name
          autofocus: on
          autocomplete: on
          type: text
          validate:
            required: true

        - name: email
          label: Email
          placeholder: Enter your email address
          type: text
          validate:
            rule: email
            required: true

    buttons:
        - type: submit
          value: Submit
        - type: reset
          value: Reset

    process:
        - save:
            fileprefix: contact-
            dateformat: Ymd-His-u
            extension: yaml
            body: "{% include 'forms/data.txt.twig' %}"
        - message: Thank you for your feedback!
---

# Nice contact form
```

In this case, you have a name and an email field. If you enable the plugin and go in the admin side, you'll see under
the Data menu the Contactform type.

Click that, and all the contact information entered is browsable, listed by filename, and you can click an item to show
its details.

# Customization

All the things listed above come out of the box, without you needing to do anything special.
Now, let's make the list more user friendly.

## List customization

By default the list shows items listed by the filename.
You might want to show some more information in the list, so you can for example have 2 columns, name and email.

Open the `user/config/plugins/data-manager.yaml` and add the structure of the data files, and the files extension.
The name of the type is the one of the data/ subfolder (in the case of the Form plugin, set by form.name)

```yaml
types:
  contactform:
    list:
      columns:
        -
            field: name
            label: Name
        -
            field: email
            label: Email
```

### Columns with nested content

To show in the items list nested content, use an array:

```yaml
list:
  columns:
    -
      field: ['address', 'email']
      label: Email
```

Will render the address.email value.

## Single item customization

```yaml
enabled: true

types:
  contactform:
    item:
      fields:
        name:
          name: Token
        email:
          name: Email
```

Will render those fields listed, and **just** those fields.
By default, the single item view lists all the fields found in the file.

## File extension customization

Usually data is saved in .yaml files. You can change that per-type by setting:

```yaml
types:
  contactform:
    file_extension: '.txt'
```

## Customize the type name

By default the Types list shows the folder name. You can add

```yaml
types:
  contactform:
    name: Contact Form
```

to provide a better name.

# Override items list and item detail templates

You can override items list and item details Twig templates using a plugin.

By doing this, you can build plugins that

1. store data in the data/ folder
2. have **complete control over the data rendering in the admin-side**

You can register the templates in the admin-side and put them in your plugin templates folder under `partials/data-manager/[typename]/item.html.twig` and/or `partials/data-manager/[typename]/items.html.twig`.

# Programmatically exclude a data folder from the Data Manager

Say you are developing a plugin that stores in the data/ folder, and you want to create your own admin interface to show the data. No problem! You can instruct the Data Manager plugin to ignore your data type (data/ subfolder).

Just listen for the `onDataTypeExcludeFromDataManagerPluginHook` event

```php
$this->enable([
    'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
]);
```

and add the data type you want to exclude by adding it to a property of the Admin Plugin:

```php
 public function onDataTypeExcludeFromDataManagerPluginHook()
 {
     $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'comments';
 }
 ```

# Future improvements

This is a first revision of the plugin.

Ideas for the near future:

- Allow plugins to define yaml for data,
- Better integration with the Form plugin (auto-determine types definition)
- Add a "process" field in the columns/fields to filter raw data (e.g. dates or booleans)
