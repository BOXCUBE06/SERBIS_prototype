import globals from 'globals'
import vuetify from 'eslint-config-vuetify'

// `vuetify()` resolves to a plain array of flat-config objects, so the
// overrides below are appended as extra configs and win by coming last.
const base = await vuetify()

/**
 * Re-enable `no-undef` inside `.vue` files.
 *
 * The stock config sets it to `error` for `**\/*.js` and friends, and then
 * `vuetify/vue/typescript__typescript-eslint/eslint-recommended` turns it
 * straight back `off` for `**\/*.vue` — typescript-eslint disables the rule
 * everywhere on the assumption the compiler already resolves identifiers.
 *
 * Every `<script setup>` in this panel is plain JS, so no compiler ever looks
 * at it: `vue-tsc` does not type-check a script block with no `lang="ts"`, and
 * `vite build` happily bundles a call to a function that does not exist. That
 * is how `errorFrom is not defined` reached production from two call sites.
 * Measured after this override: the same paste is reported as
 * "'errorFrom' is not defined".
 *
 * Globals have to be supplied here too. The config's own global list is
 * attached to the `**\/*.js` block, which `.vue` does not match, so without
 * this every `console`, `fetch` and `setTimeout` in a component would be
 * reported as undefined instead.
 */
const undefinedIdentifiers = {
  name: 'serbis/vue-no-undef',
  files: ['**/*.vue'],
  languageOptions: {
    globals: {
      ...globals.browser,
      // Compiler macros. They are never imported — the SFC compiler replaces
      // them — so to `no-undef` they look exactly like the bug it is here to
      // find.
      defineProps: 'readonly',
      defineEmits: 'readonly',
      defineExpose: 'readonly',
      defineOptions: 'readonly',
      defineModel: 'readonly',
      defineSlots: 'readonly',
      withDefaults: 'readonly',
    },
  },
  rules: {
    'no-undef': 'error',
  },
}

/**
 * Silence the rules that only move characters around.
 *
 * The first run reported 7,921 problems, of which 7,306 errors and 329
 * warnings were `--fix`-able — indentation, attribute order, arrow parens,
 * blank lines between tags. Applying that fix would rewrite nearly every file
 * under `src/` and bury every real diff for months, and leaving the rules on
 * while not fixing them buries the findings that matter under four thousand
 * indentation errors instead. Neither is worth having, so they are off.
 *
 * Off, not `warn`: a warning that appears four thousand times is not a warning
 * anybody reads. What stays on is everything that can indicate a defect rather
 * than a preference.
 *
 * `@stylistic` and `perfectionist` are switched off whole, by walking the
 * resolved config, because every rule in both plugins is layout by definition
 * and a hand-written list would go stale the next time either one ships a
 * rule. The named rules after them are the layout rules that live in plugins
 * which also carry rules worth keeping.
 */
const layoutRules = new Set()
for (const config of base) {
  for (const rule of Object.keys(config.rules ?? {})) {
    if (rule.startsWith('@stylistic/') || rule.startsWith('perfectionist/')) {
      layoutRules.add(rule)
    }
  }
}

for (const rule of [
  'vue/attributes-order',
  'vue/html-indent',
  'vue/html-self-closing',
  'vue/max-attributes-per-line',
  'vue/padding-line-between-tags',
  'vue/script-indent',
  'vue/v-slot-style',
  // Not layout, but a deliberate no: it demands `lang="ts"` on every block,
  // and these scripts are plain JS on purpose. It is also the reason
  // `no-undef` was off above, so leaving it reporting would read as advice to
  // undo the override.
  'vue/block-lang',
  // Requires `function foo()` over `const foo = () =>`. The panel is written
  // in arrow style throughout; this is a preference about which one, not a
  // defect either way.
  'antfu/top-level-function',
]) {
  layoutRules.add(rule)
}

const formattingOff = {
  name: 'serbis/formatting-off',
  rules: Object.fromEntries([...layoutRules].map(rule => [rule, 'off'])),
}

/**
 * Demote the rules that are a preference about how to write something that
 * already works.
 *
 * With the layout rules off, 154 problems remain and 130 of them are of this
 * kind: `.length` versus `.length > 0`, braces around a one-line `if`, a
 * `forEach` that could be a `for…of`. None of them can be wrong at runtime.
 * Left at `error` they keep `npx eslint src` exiting non-zero forever, so the
 * command cannot be used as a gate and a genuine finding among them goes
 * unnoticed — which is the same failure the layout rules caused, just smaller.
 *
 * What stays at `error` is the short list that can actually be a defect:
 * `no-undef` above, `unicorn/no-array-sort` (sorts the receiver in place, so a
 * prop or a computed is mutated), `unicorn/no-array-callback-reference`
 * (passes the index as a second argument to a callback that takes one),
 * `unicorn/prefer-add-event-listener` (an `onx =` assignment silently replaces
 * a handler somebody else registered), the two `regexp` rules (an unused
 * capturing group is usually a pattern that does not do what it reads like,
 * and super-linear backtracking is a hang on hostile input), and
 * `vue/custom-event-name-casing` (an emit the parent's template cannot bind).
 */
const preferenceRules = [
  'curly',
  'unicorn/catch-error-name',
  'unicorn/explicit-length-check',
  'unicorn/no-array-for-each',
  'unicorn/no-nested-ternary',
  'unicorn/numeric-separators-style',
  'unicorn/prefer-at',
  'unicorn/prefer-default-parameters',
  'unicorn/prefer-math-min-max',
  'unicorn/prefer-number-properties',
  'unicorn/prefer-optional-catch-binding',
  'unicorn/prefer-switch',
  'unicorn/prefer-ternary',
  'unicorn/switch-case-braces',
]

/*
 * Demoting a rule means naming it in a config block, and a block with no
 * `files` key applies to every file — which ENABLES a rule the stock config
 * had switched on only for some. `curly` proved it: demoted from one unscoped
 * block it went from 24 findings to 241, because `vuetify/js` scopes it to
 * `**\/*.js` and the unscoped demotion switched it on for `.vue` as well.
 *
 * So each demotion is emitted against the same file scope the stock config
 * gave that rule, read back off the resolved config rather than guessed at.
 * Severity alone is specified, which flat config applies without disturbing
 * the rule's existing options.
 */
const preferencesToWarn = []
for (const rule of preferenceRules) {
  for (const config of base) {
    if (!config.rules || !(rule in config.rules)) {
      continue
    }

    preferencesToWarn.push({
      name: `serbis/preference-warn/${rule}`,
      ...(config.files ? { files: config.files } : {}),
      rules: { [rule]: 'warn' },
    })
  }
}

/**
 * `update:modelValue` is Vue's own v-model contract, not a name this codebase
 * chose. The rule wants kebab-case and the framework requires this exact
 * spelling, so obeying it would break every `v-model` on the component — the
 * two DateTimePickerField emits it flags cannot be renamed. Every other custom
 * event still has to be kebab-case.
 */
const vModelEventName = {
  name: 'serbis/v-model-event-name',
  files: ['**/*.vue'],
  rules: {
    'vue/custom-event-name-casing': ['error', 'kebab-case', {
      ignores: ['update:modelValue'],
    }],
  },
}

export default [
  ...base,
  undefinedIdentifiers,
  formattingOff,
  ...preferencesToWarn,
  vModelEventName,
]
