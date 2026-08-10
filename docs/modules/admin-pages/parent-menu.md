<!--
    Generated from src/Modules/AdminPages/ParentMenu.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ParentMenu

The built-in WordPress menus an AdminPage can be nested under.

Returning one of these from AdminPage::parent() places the page there; returning a plain slug string nests it under a custom parent (typically another AdminPage), and returning null makes it a top-level menu.

The two admin menus hold different sections. `Sites` exists only in the network menu, and `Posts`, `Media`, `Pages`, `Comments` and `Tools` only in a site's — a page that asks for a section its `AdminMenu` does not have throws, rather than registering somewhere invisible.
