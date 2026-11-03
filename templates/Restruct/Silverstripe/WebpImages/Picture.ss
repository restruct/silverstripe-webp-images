<%-- $Image.Picture / $Image.ScaleWidth(400).Picture (restruct/silverstripe-webp-images).
     $Sources: one per WebpImages.picture_formats entry that could be produced ($Format, $Type, $SrcSet).
     $Img: the <img> tag in the image's own format, which browsers without the <source> formats show.
     Override in a theme at templates/Restruct/Silverstripe/WebpImages/Picture.ss --%>
<% if $Sources %><picture><% loop $Sources %><source type="$Type" srcset="$SrcSet"><% end_loop %>$Img</picture><% else %>$Img<% end_if %>
