import { config } from './config.js';

/** Send a text message. SMS_MODE=console only logs (development). */
export async function sendSms(to, body) {
  if (config.smsMode === 'console') {
    console.log(`[sms:dev] to ${to}: ${body}`);
    return;
  }
  const { sid, token, from } = config.twilio;
  const r = await fetch(`https://api.twilio.com/2010-04-01/Accounts/${sid}/Messages.json`, {
    method: 'POST',
    headers: {
      Authorization: 'Basic ' + Buffer.from(`${sid}:${token}`).toString('base64'),
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({ To: to, From: from, Body: body }),
  });
  if (!r.ok) throw new Error(`SMS provider error ${r.status}`);
}
