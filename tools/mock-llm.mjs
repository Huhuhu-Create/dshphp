/** 本地 mock OpenAI 兼容上游，仅用于 tools/selfcheck.mjs 端到端自检 */
import http from 'node:http';

const PORT = Number(process.env.MOCK_PORT || 18765);
let last = null;
const log = [];

function send(res, code, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

const server = http.createServer((req, res) => {
  if (req.url === '/_last') return send(res, 200, last || {});
  if (req.url === '/_log') return send(res, 200, log);
  if (req.url === '/_reset') { last = null; log.length = 0; return send(res, 200, { ok: true }); }
  if (req.url === '/v1/models') return send(res, 200, { object: 'list', data: [{ id: 'mock-model' }] });

  let raw = '';
  req.on('data', (c) => { raw += c; });
  req.on('end', () => {
    let body = {};
    try { body = JSON.parse(raw || '{}'); } catch {}
    const rec = {
      url: req.url, method: req.method,
      authorization: req.headers.authorization || null,
      model: body.model, stream: !!body.stream,
      hasTools: Array.isArray(body.tools) && body.tools.length > 0,
      toolNames: (body.tools || []).map((t) => t.function && t.function.name),
      hasTemperature: Object.prototype.hasOwnProperty.call(body, 'temperature'),
      messages: body.messages || [],
    };
    last = rec; log.push(rec);

    // /limited/* 永远返回 429，用于验证自动故障转移与冷却
    if (/\/limited(\/|$)/.test(req.url)) {
      return send(res, 429, { message: 'API rate limit exceeded', request_id: 'mock-' + Date.now() });
    }

    const users = (body.messages || []).filter((m) => m.role === 'user');
    const text = String((users.length ? users[users.length - 1] : {}).content || '');
    const model = body.model || 'mock-model';
    const base = { id: 'chatcmpl-mock', object: 'chat.completion', created: Math.floor(Date.now() / 1000), model };

    if (/fail500/.test(text)) return send(res, 500, { error: { message: 'mock upstream error' } });
    if (/fail400/.test(text) && rec.hasTools) {
      return send(res, 400, { error: { message: 'Unrecognized request argument supplied: tools' } });
    }
    if (/OK/.test(text) && users.length === 1 && !rec.hasTools) {
      return send(res, 200, { ...base, choices: [{ index: 0, message: { role: 'assistant', content: 'OK' }, finish_reason: 'stop' }], usage: { prompt_tokens: 5, completion_tokens: 1, total_tokens: 6 } });
    }

    const hasToolResult = (body.messages || []).some((m) =>
      m.role === 'tool' || (m.role === 'user' && String(m.content || '').includes('TOOL_RESULT')));
    const firstTool = rec.toolNames[0];
    const wantsCalc = /算一下|计算/.test(text);
    const calcTool = rec.toolNames.find((n) => /calc/.test(n));
    const timeTool = rec.toolNames.find((n) => /time/.test(n));
    const chosen = wantsCalc && calcTool ? calcTool : (timeTool || firstTool);
    const wantsTool = /几点|时间|算一下|计算|工具|ping/.test(text) && chosen && !/TOOL_RESULT/.test(text) && !hasToolResult;
    if (wantsTool) {
      const tc = [{ id: 'call_mock_1', type: 'function', function: { name: chosen, arguments: wantsCalc ? JSON.stringify({ expression: '(12+3)*4' }) : '{}' } }];
      return send(res, 200, {
        ...base,
        choices: [{ index: 0, message: { role: 'assistant', content: '', tool_calls: tc }, finish_reason: 'tool_calls' }],
        usage: { prompt_tokens: 20, completion_tokens: 8, total_tokens: 28 },
      });
    }
    // 文本工具协议：要求模型输出 TOOL_CALL 行
    if (/textmode/.test(text) && !/TOOL_RESULT/.test(text) && !rec.hasTools) {
      return send(res, 200, { ...base, choices: [{ index: 0, message: { role: 'assistant', content: 'TOOL_CALL {"name":"core_time__get_time","arguments":{}}' }, finish_reason: 'stop' }], usage: { total_tokens: 10 } });
    }

    let reply = '这是 mock 模型的回复。';
    if (hasToolResult || /TOOL_RESULT/.test(text)) reply = '根据工具结果：已完成计算/查询，答案是 60。';
    if (/你好|介绍/.test(text)) reply = '你好，我是 dshphp 的 mock 模型。';
    return send(res, 200, {
      ...base,
      choices: [{ index: 0, message: { role: 'assistant', content: reply }, finish_reason: 'stop' }],
      usage: { prompt_tokens: 12, completion_tokens: 9, total_tokens: 21 },
    });
  });
});

server.listen(PORT, '127.0.0.1', () => console.log(`mock-llm listening on http://127.0.0.1:${PORT}/v1`));
