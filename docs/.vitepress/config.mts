import path from 'node:path'
import { defineConfig } from 'vitepress'

// The documentation site (docs/decisions/0008): the Markdown in docs/,
// unchanged, so the contract tests, GitHub, and the site all read the
// same files. One version — the one on main — until a second is needed.

const repository = 'https://github.com/jhaskell13/laraflow'

export default defineConfig({
  title: 'Laraflow',
  description: 'Durable, recoverable workflows for Laravel.',
  // A GitHub Pages project site is served under the repository's name.
  base: '/laraflow/',
  cleanUrls: true,
  lastUpdated: true,

  // Build scaffolding and the package's own files are not pages.
  srcExclude: ['node_modules/**', 'package.json'],

  markdown: {
    config(md) {
      // A link out of docs/ — to CONTRIBUTING.md, SECURITY.md, the
      // changelog — is a file the site does not publish. On GitHub it
      // resolves; here it is rewritten to the same file on GitHub,
      // rather than copying those files in or letting them 404.
      md.core.ruler.push('laraflow-links-out-of-docs', (state) => {
        const page = state.env.relativePath ?? ''

        for (const block of state.tokens) {
          for (const token of block.children ?? []) {
            if (token.type !== 'link_open') continue

            const href = token.attrGet('href') ?? ''

            if (!href.startsWith('../')) continue

            const target = path.posix.normalize(path.posix.join(path.posix.dirname(page), href))

            if (target.startsWith('../')) {
              token.attrSet('href', `${repository}/blob/main/${target.replace(/^\.\.\//, '')}`)
            }
          }
        }
      })
    },
  },

  themeConfig: {
    nav: [
      { text: 'Get started', link: '/getting-started' },
      { text: 'Guide', link: '/guide' },
      { text: 'Reference', link: '/public-api' },
      { text: 'Changelog', link: `${repository}/blob/main/CHANGELOG.md` },
    ],

    sidebar: [
      {
        text: 'Start here',
        items: [
          { text: 'Getting started', link: '/getting-started' },
          { text: 'Tutorial: the playground', link: '/playground' },
          { text: 'Configuration', link: '/configuration' },
          { text: 'Using Laraflow', link: '/guide' },
          { text: 'Your first connector', link: '/first-connector' },
          { text: 'Limitations and design principles', link: '/limitations' },
        ],
      },
      {
        text: 'Workflows',
        items: [
          { text: 'Execution lifecycle', link: '/execution-lifecycle' },
          { text: 'Retries, timeouts, and failure', link: '/retry-and-failure-policy' },
          { text: 'Conditions, waits, sub-workflows, fan-out, compensation', link: '/advanced-workflows' },
          { text: 'Idempotency', link: '/idempotency' },
          { text: 'Changing a deployed workflow', link: '/workflow-versioning' },
          { text: 'Schedules', link: '/schedules' },
          { text: 'Triggers', link: '/triggers' },
          { text: 'Tenant ceilings', link: '/tenancy' },
        ],
      },
      {
        text: 'Running in production',
        items: [
          { text: 'Queues and recovery', link: '/queue-and-recovery' },
          { text: 'Inspection and observability', link: '/inspection-and-observability' },
          { text: 'Metrics', link: '/metrics' },
          { text: 'Tracing', link: '/tracing' },
          { text: 'The dashboard', link: '/dashboard' },
          { text: 'Retention', link: '/retention' },
          { text: 'Upgrading', link: '/upgrading' },
          { text: 'Releases and support', link: '/releases' },
        ],
      },
      {
        text: 'Connectors',
        items: [
          { text: 'Writing and using connectors', link: '/connectors' },
          { text: 'Community connectors', link: '/community-connectors' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Public API and stability', link: '/public-api' },
          { text: 'Compatibility', link: '/compatibility' },
          { text: 'Decision records', link: '/decisions/README' },
        ],
      },
    ],

    search: { provider: 'local' },

    editLink: {
      pattern: `${repository}/edit/main/docs/:path`,
      text: 'Edit this page on GitHub',
    },

    socialLinks: [{ icon: 'github', link: repository }],
  },
})
