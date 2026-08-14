import 'dart:convert';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../../../domain/models/user_profile_model.dart';
import '../../../data/repositories/profile_repository.dart';

class UserProfileNotifier extends StateNotifier<UserProfile> {
  final ProfileRepository _profileRepo;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  UserProfileNotifier(this._profileRepo, [UserProfile? initial])
      : super(initial ?? _defaultUserProfile) {
    _loadFromCache();
    _fetchFromServer();
  }

  static const UserProfile _defaultUserProfile = UserProfile(
    username: '',
    name: 'Pengguna',
    email: '',
    phone: '',
    department: '',
    division: '',
    position: '',
    avatarUrl: '',
  );

  static const List<String> presetAvatarUrls = [
    'https://i.pravatar.cc/150?u=avatar_1',
    'https://i.pravatar.cc/150?u=avatar_2',
    'https://i.pravatar.cc/150?u=avatar_3',
    'https://i.pravatar.cc/150?u=avatar_4',
    'https://i.pravatar.cc/150?u=avatar_5',
    'https://i.pravatar.cc/150?u=avatar_6',
    'https://i.pravatar.cc/150?u=avatar_7',
    'https://i.pravatar.cc/150?u=avatar_8',
  ];

  Future<void> _loadFromCache() async {
    final dataStr = await _storage.read(key: 'user_data');
    if (dataStr != null) {
      final data = jsonDecode(dataStr);
      state = _fromJson(data);
    }
  }

  Future<void> _fetchFromServer() async {
    final result = await _profileRepo.getProfile();
    if (result['success']) {
      state = _fromJson(result['data']);
    }
  }

  Future<void> refresh() async {
    await _fetchFromServer();
  }

  UserProfile _fromJson(Map<String, dynamic> data) {
    return UserProfile(
      username: data['username'] ?? '',
      name: data['name'] ?? 'Pengguna',
      email: data['email'] ?? '',
      phone: data['phone'] ?? data['no_wa'] ?? '',
      department: data['department'] ?? '',
      division: data['division'] ?? '',
      position: data['position'] ?? '',
      avatarUrl: data['avatarUrl'] ?? data['avatar_url'] ?? data['avatar'] ?? '',
    );
  }

  Future<bool> updateProfile({
    String? username,
    String? name,
    String? phone,
    String? avatarUrl,
    dynamic avatarFile,
  }) async {
    bool profileSuccess = true;
    final updateData = <String, dynamic>{};
    if (username != null) updateData['username'] = username;
    if (name != null) updateData['name'] = name;
    if (phone != null) updateData['no_wa'] = phone;

    // 1. Send text fields to backend
    if (updateData.isNotEmpty) {
      final result = await _profileRepo.updateProfile(updateData);
      if (result['success']) {
        state = _fromJson(result['data']);
      } else {
        profileSuccess = false;
      }
    }

    // 2. Upload avatar file if selected
    if (avatarFile != null) {
      final result = await _profileRepo.uploadAvatar(avatarFile);
      if (result['success']) {
        state = _fromJson(result['data']);
      } else {
        profileSuccess = false;
      }
    } else if (avatarUrl != null && avatarUrl.isNotEmpty && avatarUrl.startsWith('http')) {
      state = state.copyWith(avatarUrl: avatarUrl);
    }

    return profileSuccess;
  }

  Future<Map<String, dynamic>> deleteAccount() async {
    final result = await _profileRepo.deleteAccount();
    if (result['success']) {
      state = _defaultUserProfile;
    }
    return result;
  }
}

final userProfileProvider =
    StateNotifierProvider<UserProfileNotifier, UserProfile>(
  (ref) => UserProfileNotifier(ref.watch(profileRepositoryProvider)),
);

final adminProfileProvider =
    StateNotifierProvider<UserProfileNotifier, UserProfile>(
  (ref) => UserProfileNotifier(ref.watch(profileRepositoryProvider)),
);
