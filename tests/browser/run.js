#!/usr/bin/env node
'use strict'

// Drives tests/browser/xss.html in a real, layout-capable browser and turns
// its own results table into this process's exit code. The page's checks are
// the contract; this file only reports them. See README.md and AGENTS.md for
// what this does and does not cover, and what a missing browser looks like.

const path = require('path')

async function main () {
	let chromium
	try {
		;({ chromium } = require('playwright'))
	} catch (error) {
		skip('the "playwright" package is not installed (run "npm install" in tests/browser)')
		return
	}

	let browser
	try {
		browser = await chromium.launch()
	} catch (error) {
		skip('no Chromium browser is available for Playwright to launch (run "npx playwright install chromium" in tests/browser)')
		return
	}

	try {
		const page = await browser.newPage()
		const errors = []
		page.on('pageerror', (error) => errors.push(String(error)))

		const url = 'file://' + path.resolve(__dirname, 'xss.html')
		await page.goto(url)
		await page.waitForFunction(
			() => /^(PASS|FAIL):/.test(document.title),
			{ timeout: 10000 }
		)

		const results = await page.evaluate(() => {
			return $('#results tr').map(function () {
				const $row = $(this)
				return {
					ok: $row.find('td').eq(0).text() === 'ok',
					label: $row.find('td').eq(1).text(),
					detail: $row.find('td').eq(2).text()
				}
			}).get()
		})

		if (errors.length > 0) {
			console.error('The page itself failed to load correctly:')
			for (const error of errors) console.error('  ' + error)
		}

		let failed = 0
		for (const result of results) {
			if (result.ok) continue
			failed++
			console.error('FAIL: ' + result.label)
			if (result.detail) console.error('  ' + result.detail.replace(/\n/g, '\n  '))
		}

		const summary = await page.title()
		console.log(summary)

		if (failed > 0 || errors.length > 0 || results.length === 0) {
			process.exitCode = 1
		}
	} finally {
		await browser.close()
	}
}

function skip (reason) {
	console.error('SKIP: ' + reason)
	console.error('SKIP: the hostile-value browser checks in tests/browser/xss.html were not run')
}

main().catch((error) => {
	console.error(error)
	process.exitCode = 1
})
