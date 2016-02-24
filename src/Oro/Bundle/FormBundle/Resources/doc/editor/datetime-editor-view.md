<a name="module_DatetimeEditorView"></a>
## DatetimeEditorView ⇐ <code>[DateEditorView](./date-editor-view.md)</code>
Datetime cell content editor

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
        frontend_type: datetime
      # Sample 2. Full configuration
      {column-name-2}:
        inline_editing:
          editor:
            view: oroform/js/app/views/editor/date-editor-view
            view_options:
              css_class_name: '<class-name>'
              datePickerOptions:
                # See http://goo.gl/pddxZU
                altFormat: 'yy-mm-dd'
                changeMonth: true
                changeYear: true
                yearRange: '-80:+1'
                showButtonPanel: true
              timePickerOptions:
                # See https://github.com/jonthornton/jquery-timepicker#options
          validation_rules:
            NotBlank: ~
```

### Options in yml:

Column option name                                  | Description
:---------------------------------------------------|:-----------
inline_editing.editor.view_options.css_class_name   | Optional. Additional css class name for editor view DOM el
inline_editing.editor.view_options.dateInputAttrs   | Optional. Attributes for the date HTML input element
inline_editing.editor.view_options.datePickerOptions| Optional. See [documentation here](http://goo.gl/pddxZU)
inline_editing.editor.view_options.timeInputAttrs   | Optional. Attributes for the time HTML input element
inline_editing.editor.view_options.timePickerOptions| Optional. See [documentation here](https://goo.gl/MP6Unb)
inline_editing.editor.validation_rules | Optional. Validation rules. See [documentation](https://goo.gl/j9dj4Y)

### Constructor parameters

**Extends:** <code>[DateEditorView](./date-editor-view.md)</code>  

| Param | Type | Description |
| --- | --- | --- |
| options | <code>Object</code> | Options container |
| options.model | <code>Object</code> | Current row model |
| options.fieldName | <code>string</code> | Field name to edit in model |
| options.validationRules | <code>Object</code> | Validation rules. See [documentation here](https://goo.gl/j9dj4Y) |
| options.dateInputAttrs | <code>Object</code> | Attributes for date HTML input element |
| options.datePickerOptions | <code>Object</code> | See [documentation here](http://goo.gl/pddxZU) |
| options.timeInputAttrs | <code>Object</code> | Attributes for time HTML input element |
| options.timePickerOptions | <code>Object</code> | See [documentation here](https://goo.gl/MP6Unb) |

