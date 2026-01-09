// Streaming uploader using new process_chunk endpoint
// Sends chunks and prepends server-provided remainder.
// Supports compressed file decompression and optional chunk compression.

import {
	el,
	formatBytes,
	sniffMagicType,
	getServerCaps,
	fnv1a64,
} from "./utils.js";
import { appendServerToUrl } from "./api.js";
import { createUniversalDecompressStream, gzipSync } from "./decompressor.js";

function utf8Encode(str) {
	return new TextEncoder().encode(str);
}

function utf8Decode(bytes) {
	return new TextDecoder().decode(bytes);
}

function concatBytes(a, b) {
	const out = new Uint8Array(a.length + b.length);
	out.set(a, 0);
	out.set(b, a.length);
	return out;
}

async function startStreamUpload() {
	const fileInput = el("file");
	if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
		alert("No file selected");
		return;
	}
	let file = fileInput.files[0];
	const hash = fnv1a64(
		utf8Encode(file.name + "|" + file.size + "|" + file.lastModified)
	);

	// Stable per-import session key so multiple uploads can run in parallel.
	const importSessionId = `import-${hash}`;
	console.log("Import session id:", importSessionId);

	// Detect compression format
	const magic = await sniffMagicType(file);
	const isCompressed =
		magic === "gzip" || magic === "bzip2" || magic === "zip";
	console.log(
		`File format: ${magic}${isCompressed ? " (will decompress)" : ""}`
	);

	// Check if chunk compression is enabled
	const compressChunks = !!document.querySelector(
		"input[name='opt_compress_chunks']"
	)?.checked;

	const scope = el("import_scope")?.value || "database";
	const scopeIdent = el("import_scope_ident")?.value || "";

	// Options
	const importForm = el("importForm");
	const optNames = [
		"opt_roles",
		"opt_tablespaces",
		"opt_databases",
		"opt_schema_create",
		"opt_data",
		"opt_truncate",
		"opt_ownership",
		"opt_rights",
		"opt_defer_self",
		"opt_allow_drops",
	];
	const opts = {};
	for (const n of optNames) {
		const ck = importForm[n];
		if (ck && ck.checked) opts[n] = 1;
	}

	// Error handling mode
	const errorModeInput = document.querySelector(
		"input[name='opt_error_mode']:checked"
	);
	const errorMode = errorModeInput ? errorModeInput.value : "abort";
	if (errorMode === "abort") {
		opts.opt_stop_on_error = 1;
	}

	console.log("Import options:", opts, `Compress chunks: ${compressChunks}`);

	// UI elements
	const importUI = el("importUI");
	const importProgress = el("importProgress");
	const importStatus = el("importStatus");
	const importLog = el("importLog");
	const importStopBtn = el("importStopBtn");

	if (importUI) importUI.style.display = "block";
	if (importLog) importLog.textContent = "";

	let stopRequested = false;

	// Wire up stop button
	if (importStopBtn) {
		importStopBtn.style.display = "inline-block";
		importStopBtn.onclick = () => {
			stopRequested = true;
			importStopBtn.disabled = true;
			if (importStatus) {
				importStatus.textContent += " — Stopping...";
			}
		};
	}

	// Chunk size from config attrs
	const chunkAttr = fileInput.dataset
		? fileInput.dataset.importChunkSize
		: null;
	const chunkSize = (chunkAttr && parseInt(chunkAttr, 10)) || 5 * 1024 * 1024;

	let offset = 0;
	let bytesRead = 0; // track compressed input bytes for progress
	let remainder = ""; // server-provided incomplete tail
	const skipInput = document.querySelector("input[name='skip_statements']");
	let stuckCount = 0;

	let decompressor = createUniversalDecompressStream();
	let decompressionError = null;
	let decompressionFinished = false;

	// Buffering queue for decompressed bytes (list of Uint8Array)
	const chunkQueue = [];
	let queueLen = 0;

	// Push decompressed data into queue
	decompressor.ondata = (chunk, final) => {
		chunkQueue.push(chunk);
		queueLen += chunk.length;
		if (final) decompressionFinished = true;
	};
	decompressor.onerror = (err) => {
		decompressionError = err;
		decompressionFinished = true;
	};

	// Helper: consume N bytes from chunkQueue and return single Uint8Array
	function readFromQueue(n) {
		if (n === 0) return new Uint8Array(0);
		let out = new Uint8Array(n);
		let pos = 0;
		while (pos < n && chunkQueue.length > 0) {
			const head = chunkQueue[0];
			const take = Math.min(n - pos, head.length);
			out.set(head.subarray(0, take), pos);
			pos += take;
			if (take === head.length) {
				chunkQueue.shift();
			} else {
				chunkQueue[0] = head.subarray(take);
			}
		}
		queueLen -= pos;
		return out.subarray(0, pos);
	}

	// Backpressure thresholds
	const highWaterMark = chunkSize * 10;

	// send single payload to server (prepends remainder and handles compression/hash)
	async function sendPayload(payload, isFinal = false) {
		let payloadToSend = payload;
		let chunkCompressed = false;
		if (compressChunks && payload.length > 0) {
			payloadToSend = gzipSync(payload);
			chunkCompressed = true;
		}
		const chunkHash = fnv1a64(payloadToSend);

		const params = new URLSearchParams();
		params.set("offset", String(offset));
		params.set(
			"remainder_len",
			String(remainder ? utf8Encode(remainder).length : 0)
		);
		if (chunkCompressed) params.set("chunk_compressed", "1");
		if (isFinal) params.set("eof", "1");
		params.set("import_session_id", importSessionId);
		params.set("chunk_hash", chunkHash);
		for (const [k, v] of Object.entries(opts)) params.set(k, String(v));
		if (scope) params.set("scope", scope);
		if (scopeIdent) params.set("scope_ident", scopeIdent);

		const url = appendServerToUrl(
			"dbimport.php?action=process_chunk&" + params.toString()
		);

		const resp = await fetch(url, {
			method: "POST",
			body: payloadToSend,
			headers: { "Content-Type": "application/octet-stream" },
		});
		if (!resp.ok) {
			const text = await resp.text();
			throw new Error(`Server error (${resp.status}): ${text}`);
		}
		const res = await resp.json();

		if (res.error && res.error.includes("checksum")) {
			throw new Error(`Checksum mismatch: ${res.error}`);
		}

		// Update remainder/offset from server
		if (typeof res.remainder === "string") {
			remainder = res.remainder;
		} else {
			remainder = "";
		}
		if (typeof res.offset === "number") {
			offset = res.offset;
		} else {
			offset += payload.length; // fallback
		}

		// forward logs to UI
		if (Array.isArray(res.logEntries) && importLog) {
			for (const e of res.logEntries) {
				const msg = e.message || e.statement || "";
				if (msg && typeof msg === "string") {
					let timeMs;
					if (typeof e.time === "number") {
						timeMs = e.time > 1e11 ? e.time : e.time * 1000;
					} else {
						timeMs = Date.now();
					}
					const line = `[${new Date(timeMs).toISOString()}] ${
						e.type || "info"
					}: ${msg}`;
					importLog.textContent += line + "\n";
				}
			}
			importLog.scrollTop = importLog.scrollHeight;
		}
	}

	// Process queue: accumulate until chunkSize and send sequentially
	async function processQueue() {
		while (true) {
			// Wait for data unless decompression finished
			while (queueLen < 1 && !decompressionFinished) {
				await new Promise((r) => setTimeout(r, 20));
			}

			// Nothing left to send
			if (decompressionFinished && queueLen === 0) {
				return;
			}

			// Send either a full chunk or the final partial tail
			while (
				queueLen >= chunkSize ||
				(decompressionFinished && queueLen > 0)
			) {
				const toTake = queueLen >= chunkSize ? chunkSize : queueLen;
				const isFinalSend =
					decompressionFinished && queueLen <= chunkSize;
				const chunk = readFromQueue(toTake);
				const remBytes = remainder
					? utf8Encode(remainder)
					: new Uint8Array(0);
				let payload = chunk;
				if (remBytes.length) {
					payload = new Uint8Array(remBytes.length + chunk.length);
					payload.set(remBytes, 0);
					payload.set(chunk, remBytes.length);
				}
				await sendPayload(payload, isFinalSend);
				if (importProgress)
					importProgress.value = Math.min(
						100,
						Math.floor((bytesRead / file.size) * 100)
					);
				if (importStatus)
					importStatus.textContent = `Processed ${formatBytes(
						bytesRead
					)} / ${formatBytes(file.size)}`;
			}

			// Backpressure to avoid runaway queue growth during long decompression
			if (!decompressionFinished && queueLen > highWaterMark) {
				while (queueLen > chunkSize) {
					await new Promise((r) => setTimeout(r, 50));
				}
			}

			// Small wait to yield if we have a partial chunk but not done decompressing
			if (
				!decompressionFinished &&
				queueLen > 0 &&
				queueLen < chunkSize
			) {
				await new Promise((r) => setTimeout(r, 20));
			}
		}
	}

	// Start reader + processing
	const reader = file.stream().getReader();
	let readerErr = null;
	const queueProcessor = processQueue();
	try {
		while (true) {
			const { value, done } = await reader.read();
			if (stopRequested) {
				reader.cancel();
				return;
			}
			if (value) bytesRead += value.length;
			await decompressor.push(value || new Uint8Array(), done);
			while (queueLen > highWaterMark) {
				await new Promise((r) => setTimeout(r, 50));
			}
			if (done) break;
		}
	} catch (err) {
		readerErr = err;
		decompressionError = err;
	} finally {
		await queueProcessor;
		if (readerErr) throw readerErr;
		if (decompressionError)
			throw new Error(
				`Decompression failed: ${decompressionError.message}`
			);
	}

	// Streaming upload completed — do not run legacy chunked upload logic below.
	return;
}

document.addEventListener("frameLoaded", () => {
	// Wire up start button
	const btn = el("importStart");
	if (!btn) return;
	btn.addEventListener("click", (ev) => {
		ev.preventDefault();
		startStreamUpload();
	});
});
