// Authentication calls to the PHP backend.

import { API_BASE_URL } from "./config";

/**
 * Sends a POST request with a JSON body to the given action.
 * Returns the parsed JSON response from the API.
 */
async function postAction(action, payload) {
  const url = `${API_BASE_URL}?action=${action}`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  // The API always returns JSON, even on errors.
  const data = await response.json();
  return data;
}

/**
 * Registers a new user.
 * @param {object} payload - { full_name, email, username, password, phone, role, department_id, ... }
 */
export async function registerUser(payload) {
  return postAction("register", payload);
}

/**
 * Logs in a user.
 * @param {string} identifier - Email or username
 * @param {string} password
 */
export async function loginUser(identifier, password) {
  return postAction("login", { identifier, password });
}

/**
 * Logs out the current user by revoking their API token.
 * @param {string} token - The Bearer token
 */
export async function logoutUser(token) {
  const url = `${API_BASE_URL}?action=logout`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  return response.json();
}

/**
 * Checks if a session token is still valid.
 * @param {string} token - The Bearer token
 */
export async function checkSession(token) {
  const url = `${API_BASE_URL}?action=session-check`;
  const response = await fetch(url, {
    method: 'GET',
    headers: { 'Authorization': `Bearer ${token}` },
  });
  return response.json();
}

/**
 * Requests a password reset link/token for the given email.
 * @param {string} email
 */
export async function requestPasswordReset(param1, param2) {
  const email = param2 ? param2 : param1;
  return postAction("forgot-password", { email });
}

/**
 * Verifies if a reset token is valid and not expired.
 * @param {string} token
 */
export async function verifyResetToken(token) {
  return postAction("verify-reset-token", { token });
}

/**
 * Resets the password using the reset token.
 * @param {string} resetToken
 * @param {string} newPassword
 * @param {string} confirmPassword
 */
export async function resetPassword(arg1, arg2, arg3, arg4) {
  let token, newPassword, confirmPassword;
  if (arg4 !== undefined) {
    token = arg2;
    newPassword = arg3;
    confirmPassword = arg4;
  } else {
    token = arg1;
    newPassword = arg2;
    confirmPassword = arg3;
  }

  return postAction("reset-password", {
    token,
    new_password: newPassword,
    confirm_password: confirmPassword,
  });
}

/**
 * Changes user password when authenticated.
 * @param {string} token - Bearer token
 * @param {string} currentPassword
 * @param {string} newPassword
 * @param {string} confirmPassword
 */
export async function changePassword(token, currentPassword, newPassword, confirmPassword) {
  const url = `${API_BASE_URL}?action=change-password`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      current_password: currentPassword,
      new_password: newPassword,
      confirm_password: confirmPassword,
    }),
  });

  return response.json();
}

/**
 * Fetches the authenticated user's profile.
 */
export async function fetchMyProfile(token) {
  const url = `${API_BASE_URL}?action=my-profile`;
  const response = await fetch(url, {
    method: "GET",
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });
  return response.json();
}

/**
 * Updates the authenticated user's editable profile fields.
 */
export async function updateMyProfile(token, data) {
  const url = `${API_BASE_URL}?action=update-my-profile`;
  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      full_name: data.full_name,
      phone_number: data.phone_number,
    }),
  });
  return response.json();
}

/**
 * Uploads user profile avatar image.
 * @param {string} token - Bearer token
 * @param {string} imageUri - Local uri from expo-image-picker
 */
export async function uploadAvatar(token, imageUri) {
  const url = `${API_BASE_URL}?action=upload-avatar`;

  const filename = imageUri.split("/").pop() || "avatar.jpg";
  const match = /\.(\w+)$/.exec(filename);
  const ext = match ? match[1].toLowerCase() : "jpg";
  const mimeType = ext === "png" ? "image/png" : ext === "gif" ? "image/gif" : ext === "webp" ? "image/webp" : "image/jpeg";

  const formData = new FormData();
  formData.append("avatar", {
    uri: imageUri,
    name: filename,
    type: mimeType,
  });

  const response = await fetch(url, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
    },
    body: formData,
  });

  return response.json();
}

/**
 * Fetches courses, optionally filtered by department.
 * @param {number|string|null} departmentId
 */
export async function fetchCourses(departmentId = null) {
  const url = `${API_BASE_URL}?action=courses${
    departmentId ? `&department_id=${encodeURIComponent(departmentId)}` : ""
  }`;
  const response = await fetch(url);
  return response.json();
}

// Re-exports for backward compatibility
export { fetchClassrooms, fetchClassroom } from "./classrooms";
export { fetchEquipment, fetchEquipmentItem } from "./equipment";
export {
  createBooking,
  fetchMyBookings,
  fetchBooking,
  cancelBooking,
  cancelApprovedBooking,
  rescheduleBooking,
  checkInBooking,
  checkOutBooking,
} from "./bookings";
export { fetchPendingBookings, approveBooking, rejectBooking } from "./admin";
export {
  fetchAdminUsers,
  fetchAdminUser,
  updateAdminUserStatus,
} from "./adminUsers";
export {
  fetchMyNotifications,
  fetchNotificationCount,
  markNotificationRead,
  markAllNotificationsRead,
} from "./notifications";
