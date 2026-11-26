import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { checkSession, loginUser, logoutUser } from '../services/api/auth';
import { clearAuth, getAuth, saveAuth } from '../services/api/storage';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(null);
  const [isLoading, setIsLoading] = useState(true);

  // Keep a ref so updateUser can read the current token without a stale closure.
  const tokenRef = useRef(token);
  useEffect(() => { tokenRef.current = token; }, [token]);

  // On app start, restore and verify any saved session.
  useEffect(() => {
    (async () => {
      const { token: savedToken, user: savedUser } = await getAuth();
      if (!savedToken || !savedUser) {
        setIsLoading(false);
        return;
      }
      try {
        const result = await checkSession(savedToken);
        if (result.success) {
          setToken(savedToken);
          setUser(result.data.user);
        } else {
          await clearAuth();
        }
      } catch {
        await clearAuth();
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  const login = async (identifier, password) => {
    const result = await loginUser(identifier, password);
    if (!result.success) {
      return { success: false, message: result.message };
    }

    const { user: loggedInUser, token: rawToken } = result.data;
    await saveAuth(rawToken, loggedInUser);
    setUser(loggedInUser);
    setToken(rawToken);
    return { success: true };
  };

  /**
   * Merges a partial update into the in-memory user object and persists it
   * to storage so the nav bar (DashboardHeader) refreshes immediately.
   * Call this after a successful profile-picture upload, for example:
   *   updateUser({ profile_picture_url: newUrl });
   */
  const updateUser = (partialUpdate) => {
    setUser((current) => {
      const updated = { ...current, ...partialUpdate };
      saveAuth(tokenRef.current, updated).catch(() => {});
      return updated;
    });
  };

  const logout = async () => {
    if (tokenRef.current) {
      try {
        await logoutUser(tokenRef.current);
      } catch (e) {
        // Even if the API call fails, clear local state.
      }
    }
    await clearAuth();
    setUser(null);
    setToken(null);
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        isLoading,
        isAuthenticated: !!user,
        role: user?.role ?? null,
        login,
        logout,
        updateUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
