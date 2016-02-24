<a name="module_DateEditorView"></a>
## DateEditorView ⇐ <code>[TextEditorView](./text-editor-view.md)</code>
Date cell content editor

### Column configuration samples:
``` yml
datagrid:
  {grid-uid}:
    inline_editing:
      enable: true
    # <grid configuration> goes here
    columns:
      # Sample 1. Mapped by frontend type
      {column-name-1}:
        frontend_type: date
      # Sample 2. Full configuration
      {column-name-2}:
        inline_editing:
          editor:
            view: oroform/js/app/views/editor/date-editor-view
            view_options:
              css_class_name: '<class-name>'
              datePickerOptions:
                altFormat: 'yy-mm-dd'
                changeMonth: true
                changeYear: true
                yearRange: '-80:+1'
                showButtonPanel: true
          validation_rules:
            NotBlank: true
```

### Options in yml:

Column option name                                  | Description
:---------------------------------------------------|:-----------
inline_editing.editor.view_options.css_class_name   | Optional. Additional css class name for editor view DOM el
inline_editing.editor.view_options.dateInputAttrs   | Optional. Attributes for the date HTML input element
inline_editing.editor.view_options.datePickerOptions| Optional. See [documentation here](http://goo.gl/pddxZU)
inline_editing.editor.validation_rules | Optional. Validation rules. See [documentation](https://goo.gl/j9dj4Y)

### Constructor parameters

**Extends:** <code>[TextEditorView](./text-editor-view.md)</code>  

| Param | Type | Description |
| --- | --- | --- |
| options | <code>Object</code> | Options container |
| options.model | <code>Object</code> | Current row model |
| options.fieldName | <code>string</code> | Field name to edit in model |
| options.validationRules | <code>Object</code> | Validation rules. See [documentation here](https://goo.gl/j9dj4Y) |
| options.dateInputAttrs | <code>Object</code> | Attributes for date HTML input element |
| options.datePickerOptions | <code>Object</code> | See [documentation here](http://goo.gl/pddxZU) |

<a name="module_DateEditorView#getViewOptions"></a>
### dateEditorView.getViewOptions() ⇒ <code>Object</code>
Prepares and returns editor sub-view options

**Kind**: instance method of <code>[DateEditorView](#module_DateEditorView)</code>  
