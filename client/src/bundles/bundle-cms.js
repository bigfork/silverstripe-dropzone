/* global jQuery */
import { initDropzoneField, destroyDropzoneField } from '../components/DropzoneField/DropzoneField';

// CMS forms are frequently loaded, replaced and torn down over AJAX long after DOMContentLoaded has
// fired, so entwine - rather than a one-off scan of the document - is what drives the field here.
// jQuery comes from silverstripe/admin's global rather than an import: this module is only ever
// loaded inside the CMS, and adding jQuery to the bundle would give us a second, unrelated copy
jQuery.entwine('bigfork.dropzone', ($) => {
  $('div.dropzonefield').entwine({
    onmatch() {
      this._super();
      initDropzoneField(this[0]);
    },

    onunmatch() {
      destroyDropzoneField(this[0]);
      this._super();
    }
  });
});
