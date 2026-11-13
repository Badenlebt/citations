import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { describe, expect, it } from 'vitest'
import * as sass from 'sass'

// Example test for task 4.8 — Footer column count per breakpoint and container
// width (Req 4.4, 4.5, 7.5).
//
// Testability note: Vue `<style scoped>` is NOT compiled into applied styles
// in the Vitest/jsdom environment, so `getComputedStyle` on a mounted
// component never returns the SCSS-defined grid/container rules. Like the
// sibling SCSS tests (contrast.test.ts, typography.test.ts) we therefore
// compile the real sources with `sass` and assert against the generated CSS
// rules — the exact declarations a browser applies.
//
// Approach:
//  - The footer grid lives in the scoped `<style lang="scss">` of
//    AppFooter.vue. We extract that style block verbatim and compile it with
//    `sass.compileString`, passing the component file as the source `url` so
//    its relative `@use '../scss/variables'` / `'../scss/mixins'` imports
//    resolve against the real token/mixin sources (no reproduced grid rules).
//  - The `.container` utility lives in _utilities.scss, which we compile
//    directly with `sass.compile`.
// Path resolution uses process.cwd() (the frontend package root), matching the
// sibling tests.

const frontendRoot = process.cwd()
const appFooterPath = resolve(frontendRoot, 'src/components/AppFooter.vue')
const utilitiesPath = resolve(frontendRoot, 'src/scss/_utilities.scss')

/** Extract the inner text of the scoped `<style ... lang="scss">` block. */
function extractScopedStyle(vueSource: string): string {
  const match = vueSource.match(/<style[^>]*lang="scss"[^>]*>([\s\S]*?)<\/style>/)
  if (!match) {
    throw new Error('scoped <style lang="scss"> block not found in AppFooter.vue')
  }
  return match[1]
}

/** Compile the footer's scoped SCSS, resolving relative @use against the SFC. */
function compileFooterCss(): string {
  const source = readFileSync(appFooterPath, 'utf8')
  const scss = extractScopedStyle(source)
  // `url` lets sass resolve the component's relative `@use` paths exactly as
  // Vite does during a real build.
  return sass.compileString(scss, {
    url: pathToFileURL(appFooterPath),
    loadPaths: [resolve(frontendRoot, 'src/scss')],
  }).css
}

/** Extract the declaration block of a top-level rule with the given selector. */
function ruleBlock(css: string, selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const re = new RegExp('(?:^|[}{])\\s*' + escaped + '\\s*\\{([^}]*)\\}')
  const match = css.match(re)
  if (!match) {
    throw new Error('rule for "' + selector + '" not found in compiled CSS')
  }
  return match[1]
}

/** Extract the body of the first `@media (min-width: <px>) { ... }` block. */
function mediaBlock(css: string, minWidthPx: number): string {
  const re = new RegExp(
    '@media\\s*\\(\\s*min-width:\\s*' + minWidthPx + 'px\\s*\\)\\s*\\{',
  )
  const start = css.search(re)
  if (start === -1) {
    throw new Error('no @media (min-width: ' + minWidthPx + 'px) block found')
  }
  // Walk braces from the opening `{` of the media block to its match.
  const openIdx = css.indexOf('{', start)
  let depth = 0
  for (let i = openIdx; i < css.length; i++) {
    if (css[i] === '{') depth++
    else if (css[i] === '}') {
      depth--
      if (depth === 0) {
        return css.slice(openIdx + 1, i)
      }
    }
  }
  throw new Error('unterminated @media block')
}

/** Read a single property value from a declaration block. */
function readProperty(block: string, property: string): string {
  const re = new RegExp(
    property.replace(/[-]/g, '\\-') + '\\s*:\\s*([^;]+);',
  )
  const match = block.match(re)
  if (!match) {
    throw new Error('property "' + property + '" not declared in block')
  }
  return match[1].trim()
}

/** Collapse runs of whitespace so `repeat(2,  1fr)` compares cleanly. */
function normalise(value: string): string {
  return value.replace(/\s+/g, ' ').trim()
}

describe('AppFooter layout (task 4.8)', () => {
  const footerCss = compileFooterCss()
  const utilitiesCss = sass.compile(utilitiesPath).css

  // Req 4.5 — mobile-first: 2 columns below the md breakpoint.
  it('renders the footer grid with 2 columns below md', () => {
    const gridBlock = ruleBlock(footerCss, '.app-footer__grid')
    const columns = normalise(readProperty(gridBlock, 'grid-template-columns'))
    expect(columns).toBe('repeat(2, 1fr)')
  })

  // The footer now has two content groups (Zitate, Verwaltung), so it keeps a
  // 2-column grid at and above the md breakpoint (768px) as well — four columns
  // would be padding the layout artificially. The responsive rule still comes
  // from respond-to(md), keeping the breakpoint consistent with the rest of the
  // design (Req 4.5 / 7.5).
  it('renders the footer grid with 2 columns from md (768px)', () => {
    const md = mediaBlock(footerCss, 768)
    const gridInMd = ruleBlock(md, '.app-footer__grid')
    const columns = normalise(readProperty(gridInMd, 'grid-template-columns'))
    expect(columns).toBe('repeat(2, 1fr)')
  })

  // Guard: the responsive grid rule is anchored at the md breakpoint (768px),
  // consistent with the shared respond-to(md) mixin (Req 7.5).
  it('anchors the responsive grid rule at the 768px breakpoint', () => {
    expect(footerCss).toMatch(/@media\s*\(\s*min-width:\s*768px\s*\)/)
  })

  // Req 4.4 / 7.5 — container caps content at 1200px and is centred.
  it('limits the container to max-width 1200px', () => {
    const container = ruleBlock(utilitiesCss, '.container')
    expect(normalise(readProperty(container, 'max-width'))).toBe('1200px')
  })

  it('centres the container horizontally (margin-inline: auto)', () => {
    const container = ruleBlock(utilitiesCss, '.container')
    expect(normalise(readProperty(container, 'margin-inline'))).toBe('auto')
  })
})
