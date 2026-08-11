<!--
    Generated from commands/make/ability.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make ability

Generate an ability.

Writes a file into the plugin's `abilities/` directory, where the Abilities module discovers it. The filename becomes the ability's name, so `create-order` registers as `{plugin-slug}/create-order` — reachable over the REST API and offered to any MCP adapter on the site.

WordPress matches both halves of that name against `^[a-z0-9-]+$` and refuses anything else, so a name outside it is written as the one it accepts and the command says what it wrote: `create_order` lands as `abilities/create-order.php`.

**Two of the generated methods are placeholders, not defaults to keep.** `effect()` returns `Effect::Read` and `is_public()` returns `false`, because those are the two answers that cannot do any harm if you never revisit them — a read-only ability nothing outside your PHP can call. An ability that writes has to say so, or WordPress answers the wrong HTTP method with a 405; and one nothing can reach is one no agent will find. Both are commented in the file with what to weigh.

## Options

- **`<name>`**  
  The ability's local name, in kebab-case, e.g. `create-order`.

- **`[--dir=<dir>]`**  
  Write somewhere other than `abilities/`, relative to the plugin root.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
# Generate abilities/create-order.php.
$ wp zestry make ability create-order
Success: Created abilities/create-order.php
```
