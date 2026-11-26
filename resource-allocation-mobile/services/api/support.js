import { API_BASE_URL } from "./config";

export async function sendSupportMessage(token, subject, message) {
  const response = await fetch(`${API_BASE_URL}?action=send-support`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ subject, message }),
  });
  return response.json();
}

export async function fetchSupportMessages(token) {
  const response = await fetch(`${API_BASE_URL}?action=support-messages`, {
    method: "GET",
    headers: { Authorization: `Bearer ${token}` },
  });
  return response.json();
}

export async function replyToSupportMessage(token, id, text) {
  const response = await fetch(`${API_BASE_URL}?action=reply-support`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ message_id: id, reply: text }),
  });
  return response.json();
}

