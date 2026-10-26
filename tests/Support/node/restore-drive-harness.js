'use strict';

/**
 * Executes the console's emitted restore driver against a stubbed browser.
 *
 * The driver in `lib/recovery_view.php` is generated JavaScript that talks to
 * `fetch`, `document`, `jQuery` and `window.setTimeout`. A source assertion can
 * only read what the code says; this harness runs what the browser would run, so
 * the question "which action does the retry issue?" is answered by the sequence
 * of requests the driver actually makes.
 *
 * Usage: node restore-drive-harness.js <driver.js> <scenario>
 *
 * Prints one JSON document: `{requests: [...actions], log: [...lines]}` or
 * `{error: "..."}` with a non-zero exit code.
 */

const fs = require('fs');

const driverPath = process.argv[2];
const scenario = process.argv[3];

if (!driverPath || !scenario) {
    process.stdout.write(JSON.stringify({ error: 'usage: harness <driver.js> <scenario>' }));
    process.exit(2);
}

const state = {
    requests: [],
    log: [],
    timers: [],
};

function element() {
    return {
        style: {},
        className: '',
        classList: { add: function () {}, remove: function () {} },
        setAttribute: function () {},
        // The log is the operator-visible record: it is where a misleading
        // diagnosis would show up, so the harness collects every line.
        appendChild: function (child) {
            state.log.push(String(child.textContent));
        },
        textContent: '',
        innerHTML: '',
        scrollTop: 0,
        scrollHeight: 0,
    };
}

const elements = {};

global.document = {
    getElementById: function (id) {
        if (!elements[id]) {
            elements[id] = element();
        }
        return elements[id];
    },
    createElement: function () {
        return element();
    },
};

global.window = global;

// The drive advances through timers, not through wall-clock time: every callback
// is queued and the harness fires them one at a time, so a bounded retry budget
// can be observed without waiting 1.5 s per attempt.
global.setTimeout = function (callback) {
    state.timers.push(callback);
};

let clickHandler = null;

function jquery(target) {
    if (typeof target === 'function') {
        target();
        return { ready: function (fn) { fn(); } };
    }

    if (target === global.document) {
        return { ready: function (fn) { fn(); } };
    }

    return {
        on: function (type, handler) {
            if (type === 'click' && target === '.restore-btn') {
                clickHandler = handler;
            }
        },
        data: function (name) {
            return name === 'file' ? 'backup-probe_complete.zip' : 'complete';
        },
        modal: function () {},
        hide: function () { return this; },
        show: function () { return this; },
    };
}

global.jQuery = jquery;
global.$ = jquery;
global.confirm = function () { return true; };
global.alert = function (message) { state.log.push('alert: ' + message); };

function progress(percent, done) {
    const payload = JSON.stringify({
        step: 'restore',
        message: 'paso ' + percent + '%',
        percent: percent,
        done: done,
    });

    return 'event: progress\ndata: ' + payload + '\n\n';
}

function reply(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status: status,
        text: function () { return Promise.resolve(body); },
    };
}

function cut() {
    // What a dropped connection looks like to `fetch`: no HTTP status at all.
    return Promise.reject(new TypeError('Failed to fetch'));
}

const scenarios = {
    // `begin` never produced a response with progress, so no restore state is
    // armed on disk: the retry must ask for `begin` again.
    'begin-transport': [
        cut,
        function () { return Promise.resolve(reply(200, progress(10, false))); },
        'done',
    ],
    // Progress exists, so the state is on disk and `chunk` resumes it.
    'chunk-transport-after-progress': [
        function () { return Promise.resolve(reply(200, progress(10, false))); },
        cut,
        'done',
    ],
    // The session (or its CSRF token) was rejected: retrying cannot help.
    'unauthorized': [function () { return Promise.resolve(reply(401, '')); }],
    'forbidden': [function () { return Promise.resolve(reply(403, '')); }],
    // A server-side failure is transient: bounded retry, same action.
    'server-error': [
        function () { return Promise.resolve(reply(500, '')); },
        function () { return Promise.resolve(reply(500, '')); },
        function () { return Promise.resolve(reply(500, '')); },
        function () { return Promise.resolve(reply(500, '')); },
        function () { return Promise.resolve(reply(500, '')); },
        function () { return Promise.resolve(reply(503, '')); },
    ],
};

if (!scenarios[scenario]) {
    process.stdout.write(JSON.stringify({ error: 'unknown scenario: ' + scenario }));
    process.exit(2);
}

let issued = 0;

global.fetch = function (url) {
    const action = new URL(url, 'http://console.test').searchParams.get('action');
    state.requests.push(action);

    const scripted = scenarios[scenario];
    const step = scripted[Math.min(issued, scripted.length - 1)];
    issued++;

    return step === 'done'
        ? Promise.resolve(reply(200, progress(100, true)))
        : step();
};

async function settle() {
    for (let turn = 0; turn < 500; turn++) {
        await new Promise(function (resolve) { setImmediate(resolve); });

        if (state.timers.length === 0) {
            return;
        }

        state.timers.shift()();
    }

    throw new Error('the drive never settled');
}

(async function () {
    eval(fs.readFileSync(driverPath, 'utf8'));

    if (typeof clickHandler !== 'function') {
        throw new Error('the driver wired no restore control');
    }

    clickHandler.call({}, { preventDefault: function () {} });
    await settle();

    process.stdout.write(JSON.stringify({ requests: state.requests, log: state.log }));
})().catch(function (error) {
    process.stdout.write(JSON.stringify({ error: String(error && error.message) }));
    process.exitCode = 1;
});
