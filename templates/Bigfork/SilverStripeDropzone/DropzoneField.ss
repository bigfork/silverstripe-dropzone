<% require javascript('bigfork/silverstripe-dropzone:client/dist/js/bundle.js') %>
<% require css('bigfork/silverstripe-dropzone:client/dist/styles/bundle.css') %>
<div class="js-dropzone"></div>
<input {$AttributesHTML} />
<% if $Items %>
    <div class="dropzone-placeholder">
        <% loop $Items %>
            <input type="hidden" name="{$Up.Name}[Files][]" value="{$ID.ATT}" data-file-name="{$Name}" data-file-size="{$AbsoluteSize}" />
        <% end_loop %>
    </div>
<% end_if %>
